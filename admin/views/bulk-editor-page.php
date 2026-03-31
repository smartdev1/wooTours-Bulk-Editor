<?php
if (!defined('ABSPATH')) {
    exit;
}

use WBE\Woo\CategoryRepository;
use WBE\Woo\ProductRepository;

$categories = CategoryRepository::get_all_categories();
?>

<div class="wrap wbe-wrapper">
    <h1>
        <span class="dashicons dashicons-calendar-alt"></span>
        Édition Massive des Disponibilités Wootour
    </h1>

    <p class="description">
        Modifiez en masse les disponibilités de vos produits Wootour sans écraser les données existantes.
        Seuls les champs renseignés seront mis à jour.
    </p>

    <div id="wbe-app">

        <!-- ÉTAPE 1 : Sélection des produits -->
        <div class="wbe-step wbe-step-1 active" data-step="1">
            <div class="wbe-card">
                <div class="wbe-card-header">
                    <h2>
                        <span class="step-number">1</span>
                        Sélection des Produits
                    </h2>
                </div>

                <div class="wbe-card-body">

                    <!-- MODE DE SÉLECTION -->
                    <div class="wbe-selection-mode">
                        <label class="wbe-mode-label">
                            <input type="radio" name="selection_mode" value="categories" checked />
                            <span class="mode-icon"><span class="dashicons dashicons-category"></span></span>
                            <span class="mode-text">
                                <strong>Par catégorie</strong>
                                <small>Sélectionner tous les produits ou choisir</small>
                            </span>
                        </label>

                        <label class="wbe-mode-label">
                            <input type="radio" name="selection_mode" value="manual" />
                            <span class="mode-icon"><span class="dashicons dashicons-admin-post"></span></span>
                            <span class="mode-text">
                                <strong>Sélection libre</strong>
                                <small>Choisir des produits spécifiques</small>
                            </span>
                        </label>

                        <label class="wbe-mode-label">
                            <input type="radio" name="selection_mode" value="all" />
                            <span class="mode-icon"><span class="dashicons dashicons-screenoptions"></span></span>
                            <span class="mode-text">
                                <strong>Tout le catalogue</strong>
                                <small>Appliquer à l'ensemble des produits</small>
                            </span>
                        </label>
                    </div>

                    <!-- SÉLECTION PAR CATÉGORIE -->
                    <div class="wbe-selection-panel" id="panel-categories">
                        <!-- Étape 1A : Choix de la catégorie -->
                        <div class="wbe-category-selection" id="wbe-category-selection">
                            <div class="wbe-field-group">
                                <label for="wbe-category">
                                    <strong>Sélectionnez une catégorie</strong>
                                </label>
                                <select id="wbe-category" name="category" style="width: 100%;">
                                    <option value="">Choisir une catégorie...</option>
                                    <?php foreach ($categories as $category) : ?>
                                        <option value="<?php echo esc_attr($category->term_id); ?>">
                                            <?php echo esc_html($category->name); ?>
                                            (<?php echo esc_html($category->count); ?> produits)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Sélectionnez d'abord une catégorie, puis choisissez les produits.
                                </p>
                            </div>
                        </div>

                        <!-- Étape 1B : Choix des produits dans la catégorie -->
                        <div class="wbe-products-in-category" id="wbe-products-in-category" style="display: none;">
                            <div class="wbe-field-group">
                                <label>
                                    <strong>Choisissez les produits dans cette catégorie</strong>
                                </label>

                                <!-- Option : Tous les produits de la catégorie -->
                                <label class="wbe-option-all">
                                    <input type="radio" name="category_products_selection" value="all" />
                                    <span class="option-icon"><span class="dashicons dashicons-yes"></span></span>
                                    <span class="option-text">
                                        <strong>Tous les produits de cette catégorie</strong>
                                        <small>Appliquer à tous les <span id="wbe-category-product-count">0</span> produits</small>
                                    </span>
                                </label>

                                <!-- Option : Sélection manuelle -->
                                <label class="wbe-option-manual">
                                    <input type="radio" name="category_products_selection" value="manual" />
                                    <span class="option-icon"><span class="dashicons dashicons-filter"></span></span>
                                    <span class="option-text">
                                        <strong>Choisir des produits spécifiques</strong>
                                        <small>Sélectionner individuellement</small>
                                    </span>
                                </label>

                                <!-- Interface de sélection manuelle (cachée par défaut) -->
                                <div class="wbe-manual-selection" id="wbe-manual-selection" style="display: none;">
                                    <div class="wbe-product-search-wrapper">
                                        <input
                                            type="text"
                                            id="wbe-category-product-search"
                                            placeholder="Rechercher dans cette catégorie..."
                                            class="regular-text" />
                                        <span class="spinner"></span>
                                    </div>

                                    <!-- Liste de tous les produits de la catégorie -->
                                    <div class="wbe-product-actions">
                                        <button type="button" class="button button-small" id="wbe-select-all">
                                            <span class="dashicons dashicons-yes"></span>
                                            Tout sélectionner
                                        </button>
                                        <button type="button" class="button button-small" id="wbe-deselect-all">
                                            <span class="dashicons dashicons-no"></span>
                                            Tout désélectionner
                                        </button>
                                        <span class="wbe-selected-count">
                                            <span id="wbe-manual-selected-count">0</span> sélectionné(s)
                                        </span>
                                    </div>

                                    <!-- Résultats -->
                                    <div id="wbe-category-product-results" class="wbe-product-list"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Résumé de la sélection -->
                        <div class="wbe-selection-summary" id="wbe-category-summary" style="display: none;">
                            <div class="wbe-summary-box">
                                <span class="dashicons dashicons-category"></span>
                                <div>
                                    <div class="summary-category" id="wbe-selected-category-name"></div>
                                    <div class="summary-details">
                                        <strong id="wbe-selected-product-count">0</strong> produit(s) sélectionné(s)
                                    </div>
                                </div>
                                <button type="button" class="button button-small" id="wbe-change-category">
                                    <span class="dashicons dashicons-edit"></span>
                                    Modifier
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- SÉLECTION LIBRE (mode manuel) -->
                    <div class="wbe-selection-panel" id="panel-manual" style="display: none;">
                        <div class="wbe-field-group">
                            <label for="wbe-product-search">
                                <strong>Rechercher des produits</strong>
                            </label>
                            <div class="wbe-product-search-wrapper">
                                <input
                                    type="text"
                                    id="wbe-product-search"
                                    placeholder="Tapez le nom d'un produit (minimum 2 caractères)..."
                                    class="regular-text" />
                                <span class="spinner"></span>
                            </div>

                            <div class="wbe-product-actions">
                                <button type="button" class="button button-small" id="wbe-select-all-manual">
                                    <span class="dashicons dashicons-yes"></span>
                                    Tout sélectionner
                                </button>
                                <button type="button" class="button button-small" id="wbe-deselect-all-manual">
                                    <span class="dashicons dashicons-no"></span>
                                    Tout désélectionner
                                </button>
                            </div>
                        </div>

                        <!-- Résultats de recherche -->
                        <div id="wbe-product-results" class="wbe-product-list"></div>

                        <!-- Produits sélectionnés -->
                        <div class="wbe-selected-products-summary">
                            <h3>Produits sélectionnés</h3>
                            <div id="wbe-selected-products" class="wbe-selected-tags">
                                <p class="wbe-no-selection">Aucun produit sélectionné</p>
                            </div>
                        </div>
                    </div>

                    <!-- TOUS LES PRODUITS -->
                    <div class="wbe-selection-panel" id="panel-all" style="display: none;">
                        <div class="wbe-notice wbe-notice-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <div>
                                <strong>Attention :</strong> Vous êtes sur le point de modifier
                                <strong>TOUS les produits</strong> de votre catalogue.
                                <br>
                                Cette action peut prendre plusieurs minutes selon le nombre de produits.
                            </div>
                        </div>
                        <div class="wbe-all-products-info">
                            <span class="dashicons dashicons-info"></span>
                            Le traitement sera effectué par lots pour éviter les problèmes de performance.
                        </div>

                        <!-- Information sur le nombre de produits -->
                        <div class="wbe-field-group">
                            <label>
                                <strong>Nombre de produits dans le catalogue</strong>
                            </label>
                            <div class="wbe-total-products-count">
                                <span class="dashicons dashicons-admin-post"></span>
                                <span id="wbe-total-catalog-count">Calcul en cours...</span>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="wbe-card-footer">
                    <button type="button" class="button button-primary button-large wbe-next-step" disabled>
                        Étape suivante
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ÉTAPE 2 : Définition des modifications -->
        <div class="wbe-step wbe-step-2" data-step="2">
            <div class="wbe-card">
                <div class="wbe-card-header">
                    <h2>
                        <span class="step-number">2</span>
                        Définir les Modifications
                    </h2>
                </div>

                <div class="wbe-card-body">

                    <div class="wbe-notice wbe-notice-info">
                        <span class="dashicons dashicons-info"></span>
                        <strong>Important :</strong> Seuls les champs renseignés seront modifiés.
                        Les champs vides ne toucheront pas aux données existantes.
                    </div>

                    <!-- Plage de dates -->
                    <div class="wbe-field-row">
                        <div class="wbe-field-group wbe-field-half">
                            <label for="wbe-start-date">
                                <strong>Date de début</strong>
                            </label>
                            <input
                                type="text"
                                id="wbe-start-date"
                                name="start_date"
                                class="regular-text wbe-datepicker"
                                placeholder="jj/mm/aaaa"
                                readonly />
                        </div>

                        <div class="wbe-field-group wbe-field-half">
                            <label for="wbe-end-date">
                                <strong>Date de fin</strong>
                            </label>
                            <input
                                type="text"
                                id="wbe-end-date"
                                name="end_date"
                                class="regular-text wbe-datepicker"
                                placeholder="jj/mm/aaaa"
                                readonly />
                        </div>
                    </div>

                    <!-- Jours de la semaine -->
                    <div class="wbe-field-group">
                        <label>
                            <strong>Jours disponibles</strong>
                        </label>
                        <div class="wbe-days-selector">
                            <label class="wbe-day-checkbox">
                                <input type="checkbox" name="available_days[]" value="monday" />
                                <span>Lundi</span>
                            </label>
                            <label class="wbe-day-checkbox">
                                <input type="checkbox" name="available_days[]" value="tuesday" />
                                <span>Mardi</span>
                            </label>
                            <label class="wbe-day-checkbox">
                                <input type="checkbox" name="available_days[]" value="wednesday" />
                                <span>Mercredi</span>
                            </label>
                            <label class="wbe-day-checkbox">
                                <input type="checkbox" name="available_days[]" value="thursday" />
                                <span>Jeudi</span>
                            </label>
                            <label class="wbe-day-checkbox">
                                <input type="checkbox" name="available_days[]" value="friday" />
                                <span>Vendredi</span>
                            </label>
                            <label class="wbe-day-checkbox">
                                <input type="checkbox" name="available_days[]" value="saturday" />
                                <span>Samedi</span>
                            </label>
                            <label class="wbe-day-checkbox">
                                <input type="checkbox" name="available_days[]" value="sunday" />
                                <span>Dimanche</span>
                            </label>
                        </div>
                    </div>

                    <!-- Dates à exclure -->
                    <div class="wbe-field-group">
                        <label for="wbe-exclude-dates">
                            <strong>Exclure des dates spécifiques</strong>
                        </label>
                        <div class="wbe-calendar-wrapper">
                            <div id="wbe-exclude-calendar"></div>
                            <div class="wbe-excluded-dates-list">
                                <p class="wbe-list-title">Dates exclues :</p>
                                <ul id="wbe-excluded-dates"></ul>
                                <p class="wbe-no-excluded-dates" style="display: none;">Aucune date exclue</p>
                            </div>
                        </div>
                    </div>

                    <!-- Dates spécifiques avec calendrier (MODIFIÉ) -->
                    <div class="wbe-field-group">
                        <label for="wbe-specific-dates">
                            <strong>Ajouter des dates spécifiques</strong>
                        </label>
                        <div class="wbe-calendar-wrapper">
                            <div id="wbe-specific-calendar"></div>
                            <div class="wbe-excluded-dates-list">
                                <p class="wbe-list-title">Dates spécifiques :</p>
                                <ul id="wbe-specific-dates"></ul>
                                <p class="wbe-no-specific-dates" style="display: none;">Aucune date spécifique</p>
                            </div>
                        </div>
                        <p class="description">
                            Dates uniques avec disponibilité garantie (ignorera les exclusions).
                        </p>
                    </div>

                </div>

                <div class="wbe-card-footer">
                    <button type="button" class="button button-large wbe-prev-step">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        Retour
                    </button>
                    <button type="button" class="button button-primary button-large wbe-next-step" disabled>
                        Prévisualiser
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                    <button type="button" class="button button-large button-link-delete" id="wbe-reset-all">
                        <span class="dashicons dashicons-trash"></span>
                        Réinitialiser tout
                    </button>
                </div>
            </div>
        </div>

        <!-- ÉTAPE 3 : Prévisualisation et confirmation -->
        <div class="wbe-step wbe-step-3" data-step="3">
            <div class="wbe-card">
                <div class="wbe-card-header">
                    <h2>
                        <span class="step-number">3</span>
                        Prévisualisation et Confirmation
                    </h2>
                </div>

                <div class="wbe-card-body">

                    <div class="wbe-preview-section">
                        <h3>Produits concernés</h3>
                        <div id="wbe-preview-products" class="wbe-preview-box"></div>
                    </div>

                    <div class="wbe-preview-section">
                        <h3>Modifications à appliquer</h3>
                        <div id="wbe-preview-changes" class="wbe-preview-box"></div>
                    </div>

                    <div class="wbe-notice wbe-notice-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <strong>Attention :</strong> Cette action modifiera les disponibilités de
                        <strong id="wbe-confirm-count">0</strong> produit(s).
                        Assurez-vous que les modifications sont correctes avant de continuer.
                    </div>

                </div>

                <div class="wbe-card-footer">
                    <button type="button" class="button button-large wbe-prev-step">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        Retour
                    </button>
                    <button type="button" class="button button-primary button-large" id="wbe-apply-changes">
                        <span class="dashicons dashicons-yes"></span>
                        Appliquer les modifications
                    </button>
                    <button type="button" class="button button-large button-link-delete" id="wbe-reset-all">
                        <span class="dashicons dashicons-trash"></span>
                        Réinitialiser tout
                    </button>
                </div>
            </div>
        </div>

        <div id="wbe-loader" style="display: none;">
            <div class="wbe-loader-content">
                <div class="wbe-spinner"></div>
                <p id="wbe-loader-text">Traitement en cours...</p>
                <div class="wbe-progress-bar">
                    <div class="wbe-progress-fill" style="width: 0%"></div>
                </div>
            </div>
        </div>

        <!-- Résultat final -->
        <div class="wbe-step wbe-step-result" style="display: none;">
            <div class="wbe-card">
                <div class="wbe-card-header">
                    <h2>
                        <span class="dashicons dashicons-yes-alt"></span>
                        Résultat du traitement
                    </h2>
                </div>

                <div class="wbe-card-body">
                    <div id="wbe-result-content"></div>
                </div>

                <div class="wbe-card-footer">
                    <button type="button" class="button button-primary button-large" id="wbe-restart">
                        <span class="dashicons dashicons-update"></span>
                        Nouvelle modification
                    </button>
                </div>
            </div>
        </div>

    </div>

    <!-- Loader overlay -->
    <div id="wbe-loader" style="display: none;">
        <div class="wbe-loader-content">
            <div class="wbe-spinner"></div>
            <p id="wbe-loader-text">Traitement en cours...</p>
            <div class="wbe-progress-bar">
                <div class="wbe-progress-fill" style="width: 0%"></div>
            </div>
        </div>
    </div>

</div>