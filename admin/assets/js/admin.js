/**
 * Wootour Bulk Editor - Admin JavaScript (Enhanced - Validation Simplifiée)
 * VERSION MODIFIÉE : Suppression des restrictions sur les dates passées
 */
(function ($) {
  "use strict";

  // Variables globales pour stocker les dates
  let specificDates = [];
  let exclusionDates = [];

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
      this.setupDateManagement();
      this.updateStats();
      this.populateCategories();
    },

    /**
     * Setup date management (dates spécifiques et exclusions)
     */
    setupDateManagement: function () {
      const self = this;

      // Initialiser les datepickers pour l'ajout - PLUS DE minDate
      $("#wbe-add-specific-date").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
        // PLUS DE RESTRICTION : minDate retiré pour permettre les dates passées
      });

      $("#wbe-add-exclusion-date").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
        // PLUS DE RESTRICTION : minDate retiré pour permettre les dates passées
      });

      // Ajouter une date spécifique
      $("#wbe-add-specific-btn").on("click", function () {
        const dateInput = $("#wbe-add-specific-date");
        const dateValue = dateInput.val().trim();

        if (!dateValue) {
          self.showToast("Erreur", "Veuillez sélectionner une date", "error");
          return;
        }

        // Convertir en format YYYY-MM-DD pour le stockage
        const convertedDate = self.convertDateToYMD(dateValue);
        if (!convertedDate) {
          self.showToast("Erreur", "Format de date invalide", "error");
          return;
        }

        // Vérifier si la date n'est pas déjà ajoutée
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

      // Ajouter une date d'exclusion
      $("#wbe-add-exclusion-btn").on("click", function () {
        const dateInput = $("#wbe-add-exclusion-date");
        const dateValue = dateInput.val().trim();

        if (!dateValue) {
          self.showToast("Erreur", "Veuillez sélectionner une date", "error");
          return;
        }

        // Convertir en format YYYY-MM-DD pour le stockage
        const convertedDate = self.convertDateToYMD(dateValue);
        if (!convertedDate) {
          self.showToast("Erreur", "Format de date invalide", "error");
          return;
        }

        // Vérifier si la date n'est pas déjà exclue
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

      // Effacer toutes les dates spécifiques
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

      // Effacer toutes les exclusions
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

      // Trier les dates
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

      // Ajouter l'événement de suppression
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

      // Trier les dates
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

      // Ajouter l'événement de suppression
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

        // Validation spéciale pour le passage à l'étape 3
        if (nextStep === 3) {
          self.validateAndGoToStep3();
        } else if (self.validateStep(self.currentStep)) {
          self.goToStep(nextStep);
        }
      });

      $(".wbe-prev-step").on("click", function (e) {
        e.preventDefault();
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
     * Valider et passer à l'étape 3
     */
    validateAndGoToStep3: function () {
      const self = this;

      // Collecter les données de l'étape 2
      const formData = this.collectStep2Data();

      // Validation côté client rapide
      const clientErrors = this.validateStep2Client(formData);
      if (clientErrors.length > 0) {
        this.showValidationErrors(clientErrors);
        return;
      }

      // Afficher un indicateur de chargement
      const $button = $('.wbe-next-step[data-next="3"]');
      const originalText = $button.html();
      $button
        .html(
          '<span class="spinner is-active" style="margin: 0 5px"></span> Validation...',
        )
        .prop("disabled", true);

      // Envoyer les données au serveur pour validation
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
            // Mettre à jour les données du formulaire
            self.formData.start_date = formData.start_date;
            self.formData.end_date = formData.end_date;
            self.formData.weekdays = formData.weekdays;
            self.formData.specific_dates = formData.specific;
            self.formData.exclusions = formData.exclusions;

            // Passer à l'étape 3
            self.goToStep(3);

            // Mettre à jour le résumé
            self.updateReviewSummary(formData);

            self.showToast(
              "Validation réussie",
              "Configuration validée avec succès",
              "success",
            );
          } else {
            // Afficher les erreurs
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
     * Collecter les données de l'étape 2 - VERSION CORRIGÉE
     */
    collectStep2Data: function () {
      // ✅ CORRECTION : Utiliser directement les variables globales
      const formData = {
        start_date: this.convertDateToYMD($("#wbe-start-date").val()) || "",
        end_date: this.convertDateToYMD($("#wbe-end-date").val()) || "",
        weekdays: [],
        specific: specificDates, // ✅ Variable globale définie en haut du fichier
        exclusions: exclusionDates, // ✅ Variable globale définie en haut du fichier
      };

      // Récupérer les jours de la semaine cochés
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

      console.group("🔍 DEBUG collectStep2Data");
      console.log("Start Date:", formData.start_date);
      console.log("End Date:", formData.end_date);
      console.log("Weekdays:", formData.weekdays);
      console.log("Specific (from global var):", formData.specific);
      console.log("Exclusions (from global var):", formData.exclusions);
      console.groupEnd();

      return formData;
    },

    /**
     * Validation côté client pour l'étape 2 - VERSION ULTRA-SIMPLIFIÉE
     * Toutes les règles sont optionnelles
     * PLUS DE restriction sur les dates passées
     * PLUS DE validation de cohérence entre date début et date fin
     */
    validateStep2Client: function (formData) {
      const errors = [];

      // 1. Si les DEUX dates sont présentes, vérifier que fin >= début
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

      // 2. Vérifier les conflits entre dates spécifiques et exclusions
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

      // Ajouter la nouvelle alerte
      $(errorHtml).prependTo('.wbe-step-content[data-step="2"] .wbe-card-body');

      // Faire défiler jusqu'aux erreurs
      $("html, body").animate(
        {
          scrollTop: $('.wbe-step-content[data-step="2"]').offset().top - 50,
        },
        500,
      );

      // Permettre de fermer l'alerte
      $(document).on("click", ".notice-dismiss", function () {
        $(this).closest(".notice").remove();
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
     * Setup datepickers - VERSION MODIFIÉE : Bloquer les dates passées
     */
    setupDatepickers: function () {
      if (!$.fn.datepicker) {
        console.warn("jQuery UI Datepicker not loaded");
        return;
      }

      // ✅ MODIFICATION : Ajouter minDate: 0 pour bloquer les dates passées
      $(".wbe-datepicker").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
        changeMonth: true,
        changeYear: true,
        minDate: 0, // ✅ 0 = aujourd'hui, interdit les dates passées
      });

      $(".wbe-clear-date").on("click", function () {
        const targetId = $(this).data("target");
        $("#" + targetId)
          .val("")
          .datepicker("setDate", null);
      });
    },

    /**
     * Setup date management (dates spécifiques et exclusions)
     * VERSION COMPLÈTE ET DEBUGGÉE
     */
    setupDateManagement: function () {
      const self = this;


      // ✅ Initialiser les datepickers pour l'ajout
      $("#wbe-add-specific-date").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
        minDate: 0, // Aujourd'hui minimum
        onSelect: function (dateText, inst) {
          console.log("📅 Date spécifique sélectionnée:", dateText);
        },
      });

      $("#wbe-add-exclusion-date").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
        minDate: 0, // Aujourd'hui minimum
        onSelect: function (dateText, inst) {
          console.log("📅 Date d'exclusion sélectionnée:", dateText);
        },
      });

      console.log("✅ Datepickers initialisés");

      // ✅ ÉVÉNEMENT : Ajouter une date spécifique
      $("#wbe-add-specific-btn")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();
          console.log("🖱️ Clic sur bouton 'Ajouter date spécifique'");

          const dateInput = $("#wbe-add-specific-date");
          const dateValue = dateInput.val().trim();

          console.log("🔍 Valeur du champ:", dateValue);
          console.log(
            "🔍 Input jQuery object:",
            dateInput.length,
            "élément(s) trouvé(s)",
          );

          if (!dateValue) {
            console.warn("⚠️ Aucune date saisie");
            self.showToast("Erreur", "Veuillez sélectionner une date", "error");
            return;
          }

          // Convertir en format YYYY-MM-DD pour le stockage
          const convertedDate = self.convertDateToYMD(dateValue);
          console.log("🔄 Date convertie:", convertedDate);

          if (!convertedDate) {
            console.error("❌ Conversion échouée pour:", dateValue);
            self.showToast("Erreur", "Format de date invalide", "error");
            return;
          }

          // ✅ VALIDATION : Vérifier que la date n'est pas dans le passé
          const selectedDate = new Date(convertedDate);
          const today = new Date();
          today.setHours(0, 0, 0, 0);
          selectedDate.setHours(0, 0, 0, 0);

          console.log("📊 Comparaison dates:");
          console.log("  - Date sélectionnée:", selectedDate);
          console.log("  - Aujourd'hui:", today);
          console.log(
            "  - Est dans le passé?",
            selectedDate.getTime() < today.getTime(),
          );

          if (selectedDate.getTime() < today.getTime()) {
            console.warn("⚠️ Date dans le passé refusée");
            self.showToast(
              "Erreur",
              "Impossible d'ajouter une date passée (" +
                self.formatDateForDisplay(convertedDate) +
                ")",
              "error",
            );
            return;
          }

          // Vérifier si la date n'est pas déjà ajoutée
          console.log(
            "🔍 Vérification doublon. Liste actuelle:",
            specificDates,
          );

          if (!specificDates.includes(convertedDate)) {
            specificDates.push(convertedDate);
            console.log("✅ Date ajoutée aux dates spécifiques");
            console.log("📋 Nouvelle liste:", specificDates);

            self.updateSpecificDatesList();
            dateInput.val("");

            self.showToast(
              "Succès",
              "Date ajoutée aux dates spécifiques",
              "success",
            );
          } else {
            console.warn("⚠️ Date déjà présente dans la liste");
            self.showToast(
              "Avertissement",
              "Cette date est déjà ajoutée",
              "warning",
            );
          }
        });

      // ✅ ÉVÉNEMENT : Ajouter une date d'exclusion
      $("#wbe-add-exclusion-btn")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();
          console.log("🖱️ Clic sur bouton 'Ajouter date d'exclusion'");

          const dateInput = $("#wbe-add-exclusion-date");
          const dateValue = dateInput.val().trim();

          console.log("🔍 Valeur du champ:", dateValue);
          console.log(
            "🔍 Input jQuery object:",
            dateInput.length,
            "élément(s) trouvé(s)",
          );

          if (!dateValue) {
            console.warn("⚠️ Aucune date saisie");
            self.showToast("Erreur", "Veuillez sélectionner une date", "error");
            return;
          }

          // Convertir en format YYYY-MM-DD pour le stockage
          const convertedDate = self.convertDateToYMD(dateValue);
          console.log("🔄 Date convertie:", convertedDate);

          if (!convertedDate) {
            console.error("❌ Conversion échouée pour:", dateValue);
            self.showToast("Erreur", "Format de date invalide", "error");
            return;
          }

          // ✅ VALIDATION : Vérifier que la date n'est pas dans le passé
          const selectedDate = new Date(convertedDate);
          const today = new Date();
          today.setHours(0, 0, 0, 0);
          selectedDate.setHours(0, 0, 0, 0);

          console.log("📊 Comparaison dates:");
          console.log("  - Date sélectionnée:", selectedDate);
          console.log("  - Aujourd'hui:", today);
          console.log(
            "  - Est dans le passé?",
            selectedDate.getTime() < today.getTime(),
          );

          if (selectedDate.getTime() < today.getTime()) {
            console.warn("⚠️ Date dans le passé refusée");
            self.showToast(
              "Erreur",
              "Impossible d'ajouter une date passée (" +
                self.formatDateForDisplay(convertedDate) +
                ")",
              "error",
            );
            return;
          }

          // Vérifier si la date n'est pas déjà exclue
          console.log(
            "🔍 Vérification doublon. Liste actuelle:",
            exclusionDates,
          );

          if (!exclusionDates.includes(convertedDate)) {
            exclusionDates.push(convertedDate);
            console.log("✅ Date ajoutée aux dates d'exclusion");
            console.log("📋 Nouvelle liste:", exclusionDates);

            self.updateExclusionDatesList();
            dateInput.val("");

            self.showToast("Succès", "Date ajoutée aux exclusions", "success");
          } else {
            console.warn("⚠️ Date déjà présente dans la liste");
            self.showToast(
              "Avertissement",
              "Cette date est déjà exclue",
              "warning",
            );
          }
        });

      // ✅ ÉVÉNEMENT : Effacer toutes les dates spécifiques
      $("#wbe-clear-specific")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();
          console.log("🖱️ Clic sur 'Effacer dates spécifiques'");

          if (
            specificDates.length > 0 &&
            confirm(
              "Voulez-vous vraiment supprimer toutes les dates spécifiques ?",
            )
          ) {
            console.log(
              "🗑️ Suppression de",
              specificDates.length,
              "dates spécifiques",
            );
            specificDates = [];

            self.updateSpecificDatesList();
            self.showToast(
              "Information",
              "Toutes les dates spécifiques ont été supprimées",
              "info",
            );
          } else {
            console.log("❌ Suppression annulée ou liste vide");
          }
        });

      // ✅ ÉVÉNEMENT : Effacer toutes les exclusions
      $("#wbe-clear-exclusions")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();
          console.log("🖱️ Clic sur 'Effacer dates d'exclusion'");

          if (
            exclusionDates.length > 0 &&
            confirm("Voulez-vous vraiment supprimer toutes les exclusions ?")
          ) {
            console.log(
              "🗑️ Suppression de",
              exclusionDates.length,
              "dates d'exclusion",
            );
            exclusionDates = [];

            self.updateExclusionDatesList();
            self.showToast(
              "Information",
              "Toutes les dates exclues ont été supprimées",
              "info",
            );
          } else {
            console.log("❌ Suppression annulée ou liste vide");
          }
        });

      // ✅ Initialiser les listes au chargement
      console.log("📋 Initialisation des listes de dates");
      self.updateSpecificDatesList();
      self.updateExclusionDatesList();

      console.log("✅ setupDateManagement terminé");
      console.log("📊 État initial:");
      console.log("  - specificDates:", specificDates);
      console.log("  - exclusionDates:", exclusionDates);
    },
    /**
     * Validation côté client pour l'étape 2
     * VERSION MODIFIÉE : Ajouter validation des dates passées
     */
    validateStep2Client: function (formData) {
      const errors = [];

      // ✅ Obtenir la date d'aujourd'hui à minuit
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const todayTime = today.getTime();

      // 1. ✅ VALIDATION : Vérifier que les dates ne sont pas dans le passé
      if (formData.start_date) {
        const startDate = new Date(formData.start_date);
        startDate.setHours(0, 0, 0, 0);

        if (startDate.getTime() < todayTime) {
          errors.push(
            "La date de début ne peut pas être antérieure à aujourd'hui.",
          );
        }
      }

      if (formData.end_date) {
        const endDate = new Date(formData.end_date);
        endDate.setHours(0, 0, 0, 0);

        if (endDate.getTime() < todayTime) {
          errors.push(
            "La date de fin ne peut pas être antérieure à aujourd'hui.",
          );
        }
      }

      // 2. Validation de cohérence si les DEUX dates sont présentes
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

      // 3. ✅ VALIDATION : Vérifier que les dates spécifiques ne sont pas dans le passé
      if (formData.specific && formData.specific.length > 0) {
        const pastDates = [];

        formData.specific.forEach((dateStr) => {
          const date = new Date(dateStr);
          date.setHours(0, 0, 0, 0);

          if (date.getTime() < todayTime) {
            pastDates.push(this.formatDateForDisplay(dateStr));
          }
        });

        if (pastDates.length > 0) {
          errors.push(
            `Les dates spécifiques suivantes sont dans le passé : ${pastDates.join(", ")}. Veuillez les supprimer.`,
          );
        }
      }

      // 4. ✅ VALIDATION : Vérifier que les dates d'exclusion ne sont pas dans le passé
      if (formData.exclusions && formData.exclusions.length > 0) {
        const pastDates = [];

        formData.exclusions.forEach((dateStr) => {
          const date = new Date(dateStr);
          date.setHours(0, 0, 0, 0);

          if (date.getTime() < todayTime) {
            pastDates.push(this.formatDateForDisplay(dateStr));
          }
        });

        if (pastDates.length > 0) {
          errors.push(
            `Les dates d'exclusion suivantes sont dans le passé : ${pastDates.join(", ")}. Veuillez les supprimer.`,
          );
        }
      }

      // 5. Vérifier les conflits entre dates spécifiques et exclusions
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
     * Update review summary - VERSION AMÉLIORÉE
     */
    updateReviewSummary: function (formData) {
      const $summary = $("#wbe-review-summary");
      let html = '<div class="wbe-review-content">';

      html += `<div class="wbe-review-section"><strong>Produits sélectionnés :</strong> ${this.selectedProducts.length}</div>`;

      // Vérifier si au moins une règle est définie
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
     * Apply changes to products
     */
    applyChanges: function () {
      const self = this;

      if (this.selectedProducts.length === 0) {
        this.showToast(
          "Erreur",
          "Aucun produit sélectionné. Veuillez sélectionner au moins un produit à l'étape 1.",
          "error",
        );
        return;
      }

      // ✅ CORRECTION : Utiliser les variables globales specificDates et exclusionDates
      const formData = {
        start_date: this.formData.start_date,
        end_date: this.formData.end_date,
        weekdays: this.formData.weekdays,
        specific: specificDates, // ✅ Variable globale
        exclusions: exclusionDates, // ✅ Variable globale
      };

      console.group("🔍 DEBUG applyChanges");
      console.log("selectedProducts:", this.selectedProducts);
      console.log("formData:", formData);
      console.log("specificDates (global):", specificDates);
      console.log("exclusionDates (global):", exclusionDates);
      console.groupEnd();

      const $applyBtn = $("#wbe-apply-btn");
      const $progressContainer = $("#wbe-progress-container");
      const $progressFill = $("#wbe-progress-fill");
      const $progressText = $("#wbe-progress-text");

      // Préparer weekdays au format attendu par le serveur
      const weekdaysObj = {};
      $(".wbe-weekday-checkbox:checked").each(function () {
        const dayName = $(this)
          .attr("name")
          .match(/\[(.*?)\]/)[1];
        weekdaysObj[dayName] = "on";
      });

      $progressContainer.show();
      $applyBtn.prop("disabled", true);
      $progressText.text("⏳ Application des modifications en cours...");

      const startTime = Date.now();
      const updateTimer = setInterval(function () {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        $progressText.text(`⏳ Application en cours... (${elapsed}s)`);
      }, 1000);

      const ajaxAction =
        wbe_admin_data.ajax_actions?.process_batch || "wbe_process_batch";

      // ✅ CORRECTION : Envoyer les bonnes données
      const ajaxData = {
        action: ajaxAction,
        nonce: wbe_admin_data.nonce,
        product_ids: this.selectedProducts,
        start_date: formData.start_date,
        end_date: formData.end_date,
        weekdays: weekdaysObj,
        specific: formData.specific, // ✅ Dates spécifiques depuis la variable globale
        exclusions: formData.exclusions, // ✅ Dates d'exclusion depuis la variable globale
        debug: true,
        timestamp: Date.now(),
      };

      console.log("📤 Données envoyées au serveur:", ajaxData);

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
            $progressText.text(
              `✅ Modifications appliquées avec succès en ${elapsed}s`,
            );

            const results = response.data?.results;

            if (results) {
              const successCount = results.success ? results.success.length : 0;
              const totalCount = results.total || self.selectedProducts.length;

              let successMsg = `✅ ${successCount}/${totalCount} produit(s) mis à jour avec succès`;
              self.showToast("Succès", successMsg, "success");

              if (results.failed && results.failed.length > 0) {
                self.showDetailedErrors(results.failed);
              }
            } else {
              const message =
                response.data?.message ||
                "Modifications appliquées avec succès";
              self.showToast("Succès", message, "success");
            }
          } else {
            const errorMsg =
              response.data?.message ||
              "Erreur lors de l'application des modifications";
            const errorCode = response.data?.code || "UNKNOWN_ERROR";

            self.showToast("Erreur", errorMsg, "error");
            $progressText.text(`❌ Échec: ${errorMsg}`);

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

          self.showToast("Erreur", errorMsg, "error");
          $progressText.text(`❌ ${errorMsg}`);

          if (errorDetails) {
            setTimeout(function () {
              self.showToast("Information", errorDetails, "info");
            }, 500);
          }

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
        // Compatibilité avec l'ancienne signature
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

      // Si déjà en YYYY-MM-DD
      if (dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) {
        return dateStr;
      }

      // Si en DD/MM/YYYY, convertir
      if (dateStr.match(/^(\d{2})\/(\d{2})\/(\d{4})$/)) {
        const parts = dateStr.split("/");
        return parts[2] + "-" + parts[1] + "-" + parts[0]; // YYYY-MM-DD
      }

      // Si le datepicker a retourné une date différente
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
