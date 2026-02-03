/**
 * Gestion du calendrier interactif pour l'édition en masse
 * Utilise jQuery UI Datepicker (déjà présent dans WordPress)
 * 
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Classe principale du calendrier
     */
    class WootourBulkCalendar {
        constructor() {
            this.calendarIds = ['#bulk-start-date', '#bulk-end-date', '#bulk-exclude-dates', '#bulk-specific-dates'];
            this.selectedDates = {
                startDate: null,
                endDate: null,
                excludeDates: [],
                specificDates: []
            };
            this.dateFormat = 'yy-mm-dd';
            this.init();
        }

        /**
         * Initialisation du calendrier
         */
        init() {
            this.initStartDateCalendar();
            this.initEndDateCalendar();
            this.initExcludeDatesCalendar();
            this.initSpecificDatesCalendar();
            this.bindEvents();
            this.updateDateSummary();
        }

        /**
         * Calendrier pour la date de début
         */
        initStartDateCalendar() {
            const self = this;
            
            $('#bulk-start-date').datepicker({
                dateFormat: self.dateFormat,
                minDate: 0, 
                onSelect: function(dateText) {
                    self.selectedDates.startDate = dateText;
                    self.updateEndDateMinDate(dateText);
                    self.updateDateSummary();
                    
                    if (self.selectedDates.endDate && self.compareDates(dateText, self.selectedDates.endDate) > 0) {
                        self.selectedDates.endDate = null;
                        $('#bulk-end-date').val('');
                    }
                },
                beforeShow: function(input, inst) {
                    setTimeout(function() {
                        inst.dpDiv.addClass('wootour-bulk-calendar');
                    }, 0);
                }
            });
        }

        /**
         * Calendrier pour la date de fin
         */
        initEndDateCalendar() {
            const self = this;
            
            $('#bulk-end-date').datepicker({
                dateFormat: self.dateFormat,
                minDate: 0,
                onSelect: function(dateText) {
                    self.selectedDates.endDate = dateText;
                    self.updateDateSummary();
                    if (self.selectedDates.startDate && self.compareDates(self.selectedDates.startDate, dateText) > 0) {
                        alert('La date de fin doit être postérieure ou égale à la date de début.');
                        self.selectedDates.endDate = null;
                        $(this).val('');
                    }
                },
                beforeShow: function(input, inst) {
                    setTimeout(function() {
                        inst.dpDiv.addClass('wootour-bulk-calendar');
                    }, 0);
                }
            });
        }

        /**
         * Calendrier pour les dates à exclure (sélection multiple)
         */
        initExcludeDatesCalendar() {
            const self = this;
            let currentInput = '';
            
            $('#bulk-exclude-dates').datepicker({
                dateFormat: self.dateFormat,
                minDate: 0,
                beforeShowDay: function(date) {
                    const dateString = $.datepicker.formatDate(self.dateFormat, date);
                    const isSelected = self.selectedDates.excludeDates.includes(dateString);
                    
                    return [true, isSelected ? 'selected-date' : '', ''];
                },
                onSelect: function(dateText) {
                    currentInput = '#bulk-exclude-dates';
                    self.toggleDateSelection(dateText, 'excludeDates');
                },
                onChangeMonthYear: function(year, month, inst) {
                    setTimeout(function() {
                        $(inst.dpDiv).find('.ui-datepicker-calendar a').each(function() {
                            const dateText = $(this).text();
                        });
                    }, 10);
                },
                beforeShow: function(input, inst) {
                    currentInput = '#bulk-exclude-dates';
                    setTimeout(function() {
                        inst.dpDiv.addClass('wootour-bulk-calendar multi-select');
                    }, 0);
                }
            });
        }

        /**
         * Calendrier pour les dates spécifiques (sélection multiple)
         */
        initSpecificDatesCalendar() {
            const self = this;
            let currentInput = '';
            
            $('#bulk-specific-dates').datepicker({
                dateFormat: self.dateFormat,
                minDate: 0,
                beforeShowDay: function(date) {
                    const dateString = $.datepicker.formatDate(self.dateFormat, date);
                    const isSelected = self.selectedDates.specificDates.includes(dateString);
                    
                    return [true, isSelected ? 'selected-date specific-date' : '', ''];
                },
                onSelect: function(dateText) {
                    currentInput = '#bulk-specific-dates';
                    self.toggleDateSelection(dateText, 'specificDates');
                },
                beforeShow: function(input, inst) {
                    currentInput = '#bulk-specific-dates';
                    setTimeout(function() {
                        inst.dpDiv.addClass('wootour-bulk-calendar multi-select');
                    }, 0);
                }
            });
        }

        /**
         * Basculer la sélection d'une date (ajouter/retirer)
         * @param {string} dateText
         * @param {string} dateType
         */
        toggleDateSelection(dateText, dateType) {
            const index = this.selectedDates[dateType].indexOf(dateText);
            
            if (index === -1) {
                this.selectedDates[dateType].push(dateText);
            } else {
                this.selectedDates[dateType].splice(index, 1);
            }
            
            this.selectedDates[dateType].sort();
            
            this.updateInputField(dateType);
            this.updateDateSummary();
            this.refreshCalendar(dateType);
        }

        /**
         * Mettre à jour le champ input avec les dates sélectionnées
         * @param {string} dateType
         */
        updateInputField(dateType) {
            const inputId = dateType === 'excludeDates' ? '#bulk-exclude-dates' : '#bulk-specific-dates';
            const datesString = this.selectedDates[dateType].join(',');
            $(inputId).val(datesString);
        }

        /**
         * Rafraîchir l'affichage d'un calendrier
         * @param {string} dateType 
         */
        refreshCalendar(dateType) {
            const inputId = dateType === 'excludeDates' ? '#bulk-exclude-dates' : '#bulk-specific-dates';
            $(inputId).datepicker('refresh');
        }

        /**
         * Mettre à jour la date minimum pour le calendrier de fin
         * @param {string} startDate
         */
        updateEndDateMinDate(startDate) {
            if (startDate) {
                $('#bulk-end-date').datepicker('option', 'minDate', startDate);
            }
        }

        /**
         * Comparer deux dates
         * @param {string} date1 
         * @param {string} date2
         * @returns {number} 
         */
        compareDates(date1, date2) {
            const d1 = new Date(date1);
            const d2 = new Date(date2);
            
            if (d1 < d2) return -1;
            if (d1 > d2) return 1;
            return 0;
        }

        /**
         * Mettre à jour le résumé des dates sélectionnées
         */
        updateDateSummary() {
            let summary = [];
            
            if (this.selectedDates.startDate) {
                summary.push(`<strong>Du :</strong> ${this.formatDisplayDate(this.selectedDates.startDate)}`);
            }
            
            if (this.selectedDates.endDate) {
                summary.push(`<strong>Au :</strong> ${this.formatDisplayDate(this.selectedDates.endDate)}`);
            }
            
            if (this.selectedDates.excludeDates.length > 0) {
                const count = this.selectedDates.excludeDates.length;
                summary.push(`<strong>Dates exclues :</strong> ${count} date${count > 1 ? 's' : ''}`);
            }
            
            if (this.selectedDates.specificDates.length > 0) {
                const count = this.selectedDates.specificDates.length;
                summary.push(`<strong>Dates spécifiques :</strong> ${count} date${count > 1 ? 's' : ''}`);
            }
            
            if (summary.length === 0) {
                summary.push('<em>Aucune date sélectionnée</em>');
            }
            
            $('#date-summary').html(summary.join('<br>'));
        }

        /**
         * Formater une date pour l'affichage
         * @param {string} dateString
         * @returns {string}
         */
        formatDisplayDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        /**
         * Effacer toutes les sélections de dates
         */
        clearAllDates() {
            this.selectedDates = {
                startDate: null,
                endDate: null,
                excludeDates: [],
                specificDates: []
            };
            
            this.calendarIds.forEach(id => {
                $(id).val('');
            });
            
            $('#bulk-end-date').datepicker('option', 'minDate', 0);
            this.calendarIds.forEach(id => {
                if ($(id).hasClass('hasDatepicker')) {
                    $(id).datepicker('refresh');
                }
            });
            
            this.updateDateSummary();
        }

        /**
         * Récupérer les dates sélectionnées pour soumission
         * @returns {Object}
         */
        getSelectedDates() {
            return {
                start_date: this.selectedDates.startDate,
                end_date: this.selectedDates.endDate,
                exclude_dates: this.selectedDates.excludeDates.join(','),
                specific_dates: this.selectedDates.specificDates.join(','),
                week_days: this.getSelectedWeekDays()
            };
        }

        /**
         * Récupérer les jours de la semaine sélectionnés
         * @returns {string}
         */
        getSelectedWeekDays() {
            const selectedDays = [];
            $('input[name="week_days[]"]:checked').each(function() {
                selectedDays.push($(this).val());
            });
            return selectedDays.join(',');
        }

        /**
         * Lier les événements
         */
        bindEvents() {
            const self = this;
            $('#clear-dates').on('click', function(e) {
                e.preventDefault();
                if (confirm('Êtes-vous sûr de vouloir effacer toutes les sélections de dates ?')) {
                    self.clearAllDates();
                }
            });
            
            $('.multi-date-input').on('focus', function() {
                $(this).addClass('active-calendar');
            }).on('blur', function() {
                $(this).removeClass('active-calendar');
            });
            
            $('#bulk-edit-form').on('submit', function(e) {
                const dates = self.getSelectedDates();
                const hasDates = dates.start_date || dates.end_date || dates.exclude_dates || 
                                dates.specific_dates || dates.week_days;
                
                if (!hasDates) {
                    e.preventDefault();
                    alert('Veuillez sélectionner au moins une date ou un jour de la semaine.');
                    return false;
                }
                
                if (dates.start_date && dates.end_date && self.compareDates(dates.start_date, dates.end_date) > 0) {
                    e.preventDefault();
                    alert('La date de début doit être antérieure ou égale à la date de fin.');
                    return false;
                }
                
                return true;
            });
        }
    }

    /**
     * Initialisation quand le DOM est prêt
     */
    $(document).ready(function() {
        if (!$.datepicker) {
            return;
        }
        
        window.wootourBulkCalendar = new WootourBulkCalendar();
        window.WootourBulkCalendar = {
            getSelectedDates: function() {
                return window.wootourBulkCalendar.getSelectedDates();
            },
            clearDates: function() {
                window.wootourBulkCalendar.clearAllDates();
            }
        };
    });

})(jQuery);