<?php

namespace WootourBulkEditor\Controllers;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Services\SecurityService;
use WootourBulkEditor\Repositories\ProductRepository;
use WootourBulkEditor\Core\Traits\Singleton;

defined('ABSPATH') || exit;

class AdminController
{
    use Singleton;

    private $security_service;
    private $product_repository;
    private string $page_hook = '';

    protected function __construct()
    {
        // Dependencies will be injected via init
    }

    public function init(): void
    {
        $this->security_service = SecurityService::getInstance();
        $this->product_repository = ProductRepository::getInstance();

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('plugin_action_links_' . Constants::BASENAME, [$this, 'add_plugin_action_links']);
    }

    public function register_admin_menu(): void
    {
        if (!$this->user_can_access()) {
            return;
        }

        $this->page_hook = add_submenu_page(
            'woocommerce',
            __('Wootour Bulk Editor', Constants::TEXT_DOMAIN),
            __('WooTour Edition de Masse', Constants::TEXT_DOMAIN),
            'manage_woocommerce',
            'wootour-bulk-edit',
            [$this, 'render_admin_page']
        );

        add_action('load-' . $this->page_hook, [$this, 'add_help_tabs']);
    }

    private function user_can_access(): bool
    {
        foreach (Constants::REQUIRED_CAPS as $role => $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }
        return false;
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== $this->page_hook) {
            return;
        }

        // Enqueue jQuery UI Datepicker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style(
            'jquery-ui-style',
            'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css',
            [],
            '1.12.1'
        );

        // Plugin CSS
        wp_enqueue_style(
            'wbe-admin',
            Constants::plugin_url() . '../admin/assets/css/wb-admin.css',
            ['wp-components', 'jquery-ui-style'],
            Constants::VERSION
        );


        // Plugin JS
        wp_enqueue_script(
            'wbe-admin',
            Constants::plugin_url() . '../admin/assets/js/admin.js',
            ['jquery', 'jquery-ui-datepicker', 'wp-util', 'wp-i18n'],
            Constants::VERSION,
            true
        );

        $this->localize_admin_script();
    }

    private function localize_admin_script(): void
    {
        error_log('[WBE] Localizing admin script');
        error_log('[WBE] AJAX URL: ' . admin_url('admin-ajax.php'));

        $localization_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $this->security_service->create_nonce('ajax'),
            'admin_nonce' => $this->security_service->create_nonce('admin_page'),
            'batch_size' => Constants::BATCH_SIZE,
            'timeout_seconds' => Constants::TIMEOUT_SECONDS,
            'date_format' => Constants::DATE_FORMATS['display'],
            'date_format_js' => Constants::DATE_FORMATS['js'],
            'i18n' => $this->get_i18n_strings(),

            // ✅ AJOUTER pour debug
            'ajax_actions' => Constants::AJAX_ACTIONS,

            // ✅ Utiliser la fonction corrigée SANS filtre Wootour pour le dropdown
            'categories' => $this->product_repository->getCategoryTree(),

            // ✅ Statistiques mises à jour
            'statistics' => [
                // Total de TOUS les produits
                'total_products' => $this->product_repository->getProductCount(0, false),

                // Nombre de catégories
                'categories_count' => count($this->product_repository->getCategoriesWithCounts(false)),

                // Nombre de produits AVEC données Wootour
                'with_wootour' => $this->product_repository->getProductCount(0, true),
            ],
        ];

        error_log('[WBE] Nonce created: ' . $localization_data['nonce']);

        wp_localize_script('wbe-admin', 'wbe_admin_data', $localization_data);
    }

    private function get_i18n_strings(): array
    {
        return [
            'loading' => __('Chargement...', Constants::TEXT_DOMAIN),
            'saving' => __('Enregistrement...', Constants::TEXT_DOMAIN),
            'processing' => __('Traitement...', Constants::TEXT_DOMAIN),
            'error' => __('Erreur', Constants::TEXT_DOMAIN),
            'success' => __('Succès', Constants::TEXT_DOMAIN),
            'noProductsSelected' => __('Veuillez sélectionner au moins un produit.', Constants::TEXT_DOMAIN),
            'noChangesSpecified' => __('Veuillez spécifier au moins une modification.', Constants::TEXT_DOMAIN),
            'confirmApply' => __('Êtes-vous sûr de vouloir appliquer ces modifications ?', Constants::TEXT_DOMAIN),
        ];
    }

    public function render_admin_page(): void
    {
        if (!$this->user_can_access()) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', Constants::TEXT_DOMAIN));
        }

        // Get plugin data
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data(Constants::plugin_dir() . 'wootour-bulk-editor.php');

?>
        <div class="wrap wbe-admin-wrap">
            <header class="wbe-header">
                <h1 class="wp-heading-inline">
                    <span class="wbe-icon">📅</span>
                    <?php echo esc_html__('Edition de Masse - Tours WooCommerce', Constants::TEXT_DOMAIN); ?>
                </h1>

                <div class="wbe-header-actions">
                    <button type="button" class="button button-secondary wbe-help-toggle">
                        <span class="dashicons dashicons-editor-help"></span>
                        <?php _e('Aide', Constants::TEXT_DOMAIN); ?>
                    </button>
                    <button type="button" class="button button-secondary wbe-view-logs">
                        <span class="dashicons dashicons-list-view"></span>
                        <?php _e('Voir les Logs', Constants::TEXT_DOMAIN); ?>
                    </button>
                </div>
            </header>

            <?php if (!Constants::is_wootour_active()): ?>
                <div class="notice notice-warning">
                    <p><?php _e('Le plugin WooTours n\'est pas actif. Certaines fonctionnalités peuvent être limitées.', Constants::TEXT_DOMAIN); ?></p>
                </div>
            <?php endif; ?>

            <div class="wbe-stats-bar">
                <div class="wbe-stat">
                    <span class="wbe-stat-label"><?php _e('Total Produits', Constants::TEXT_DOMAIN); ?></span>
                    <span class="wbe-stat-value" id="wbe-total-products">0</span>
                </div>
                <div class="wbe-stat">
                    <span class="wbe-stat-label"><?php _e('Sélectionnés', Constants::TEXT_DOMAIN); ?></span>
                    <span class="wbe-stat-value" id="wbe-selected-count">0</span>
                </div>
                <div class="wbe-stat">
                    <span class="wbe-stat-label"><?php _e('Avec WooTours', Constants::TEXT_DOMAIN); ?></span>
                    <span class="wbe-stat-value" id="wbe-wootour-count">0</span>
                </div>
                <div class="wbe-stat">
                    <span class="wbe-stat-label"><?php _e('Version', Constants::TEXT_DOMAIN); ?></span>
                    <span class="wbe-stat-value"><?php echo esc_html($plugin_data['Version']); ?></span>
                </div>
            </div>

            <!-- STEPS INDICATOR -->
            <div class="wbe-steps">
                <div class="wbe-step active" data-step="1">
                    <span class="wbe-step-number">1</span>
                    <span class="wbe-step-label"><?php _e('Sélection Produits', Constants::TEXT_DOMAIN); ?></span>
                </div>
                <div class="wbe-step" data-step="2">
                    <span class="wbe-step-number">2</span>
                    <span class="wbe-step-label"><?php _e('Règles de Disponibilité', Constants::TEXT_DOMAIN); ?></span>
                </div>
                <div class="wbe-step" data-step="3">
                    <span class="wbe-step-number">3</span>
                    <span class="wbe-step-label"><?php _e('Révision & Application', Constants::TEXT_DOMAIN); ?></span>
                </div>
            </div>

            <!-- STEP 1: Product Selection -->
            <section class="wbe-step-content active" data-step="1">
                <div class="wbe-card">
                    <div class="wbe-card-header">
                        <h2><?php _e('1. Sélectionner les Produits', Constants::TEXT_DOMAIN); ?></h2>
                    </div>
                    <div class="wbe-card-body">
                        <div class="wbe-section">
                            <h3><?php _e('Filtrer par Catégorie', Constants::TEXT_DOMAIN); ?></h3>
                            <div class="wbe-category-filter">
                                <select id="wbe-category-select" class="wbe-select">
                                    <option value="0"><?php _e('Toutes les Catégories', Constants::TEXT_DOMAIN); ?></option>
                                </select>
                                <button type="button" id="wbe-load-category" class="button button-secondary">
                                    <?php _e('Charger', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>

                        <div class="wbe-section">
                            <h3><?php _e('Rechercher des Produits', Constants::TEXT_DOMAIN); ?></h3>
                            <div class="wbe-search-box">
                                <input type="text" id="wbe-product-search" class="wbe-search-input"
                                    placeholder="<?php _e('Rechercher des produits...', Constants::TEXT_DOMAIN); ?>">
                                <button type="button" id="wbe-search-btn" class="button button-secondary">
                                    <?php _e('Rechercher', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>

                        <div class="wbe-section">
                            <div class="wbe-product-list-header">
                                <h3><?php _e('Produits', Constants::TEXT_DOMAIN); ?></h3>
                                <div>
                                    <button type="button" id="wbe-select-all" class="button button-small">
                                        <?php _e('Tout Sélectionner', Constants::TEXT_DOMAIN); ?>
                                    </button>
                                    <button type="button" id="wbe-deselect-all" class="button button-small">
                                        <?php _e('Tout Désélectionner', Constants::TEXT_DOMAIN); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="wbe-product-list-container">
                                <div id="wbe-product-list" class="wbe-loading">
                                    <span class="spinner is-active"></span>
                                    <span><?php _e('Chargement des produits...', Constants::TEXT_DOMAIN); ?></span>
                                </div>
                            </div>
                            <div class="wbe-pagination">
                                <button type="button" id="wbe-prev-page" class="button" disabled>
                                    <?php _e('Précédent', Constants::TEXT_DOMAIN); ?>
                                </button>
                                <span class="wbe-page-info">
                                    <span id="wbe-current-page">1</span> / <span id="wbe-total-pages">1</span>
                                </span>
                                <button type="button" id="wbe-next-page" class="button" disabled>
                                    <?php _e('Suivant', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wbe-step-actions">
                    <button class="button button-primary wbe-next-step" data-next="2">
                        <?php _e('Suivant: Disponibilité →', Constants::TEXT_DOMAIN); ?>
                    </button>
                </div>
            </section>

            <!-- STEP 2: Availability Rules -->
            <section class="wbe-step-content" data-step="2">
                <div class="wbe-card">
                    <div class="wbe-card-header">
                        <h2><?php _e('2. Définir la Disponibilité', Constants::TEXT_DOMAIN); ?></h2>
                    </div>
                    <div class="wbe-card-body">
                        <div class="wbe-section">
                            <h3><?php _e('Plage de Dates', Constants::TEXT_DOMAIN); ?></h3>
                            <div class="wbe-date-range">
                                <div class="wbe-date-field">
                                    <label for="wbe-start-date"><?php _e('Premier jour', Constants::TEXT_DOMAIN); ?></label>
                                    <input type="text" id="wbe-start-date" class="wbe-datepicker" readonly>
                                    <button type="button" class="button button-small wbe-clear-date" data-target="wbe-start-date">
                                        <?php _e('Effacer', Constants::TEXT_DOMAIN); ?>
                                    </button>
                                </div>
                                <div class="wbe-date-field">
                                    <label for="wbe-end-date"><?php _e('Date de fin', Constants::TEXT_DOMAIN); ?></label>
                                    <input type="text" id="wbe-end-date" class="wbe-datepicker" readonly>
                                    <button type="button" class="button button-small wbe-clear-date" data-target="wbe-end-date">
                                        <?php _e('Effacer', Constants::TEXT_DOMAIN); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="wbe-section">
                            <h3><?php _e('Jours de la Semaine Disponibles', Constants::TEXT_DOMAIN); ?></h3>
                            <div class="wbe-weekdays">
                                <?php
                                $days = [
                                    'monday' => __('Lundi', Constants::TEXT_DOMAIN),
                                    'tuesday' => __('Mardi', Constants::TEXT_DOMAIN),
                                    'wednesday' => __('Mercredi', Constants::TEXT_DOMAIN),
                                    'thursday' => __('Jeudi', Constants::TEXT_DOMAIN),
                                    'friday' => __('Vendredi', Constants::TEXT_DOMAIN),
                                    'saturday' => __('Samedi', Constants::TEXT_DOMAIN),
                                    'sunday' => __('Dimanche', Constants::TEXT_DOMAIN),
                                ];
                                foreach ($days as $key => $label):
                                ?>
                                    <label class="wbe-checkbox-label">
                                        <input type="checkbox" class="wbe-weekday-checkbox" name="weekdays[<?php echo $key; ?>]">
                                        <span><?php echo esc_html($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ... Code existant ... -->

                        <div class="wbe-section">
                            <h3><?php _e('Dates Spécifiques', Constants::TEXT_DOMAIN); ?></h3>

                            <!-- AJOUTER: Zone d'ajout de date -->
                            <div class="wbe-add-date">
                                <div class="wbe-date-field">
                                    <label for="wbe-add-specific-date"><?php _e('Ajouter une date', Constants::TEXT_DOMAIN); ?></label>
                                    <input type="text" id="wbe-add-specific-date" class="wbe-datepicker" placeholder="<?php _e('Cliquez pour choisir une date', Constants::TEXT_DOMAIN); ?>" readonly>
                                    <button type="button" id="wbe-add-specific-btn" class="button button-primary">
                                        <?php _e('Ajouter', Constants::TEXT_DOMAIN); ?>
                                    </button>
                                </div>
                                <p class="description"><?php _e('Ajoutez des dates spécifiques où l\'activité sera disponible.', Constants::TEXT_DOMAIN); ?></p>
                            </div>

                            <div class="wbe-selected-dates">
                                <h4><?php _e('Dates Sélectionnées', Constants::TEXT_DOMAIN); ?>:</h4>
                                <div id="wbe-specific-dates-list" class="wbe-dates-list"></div>
                                <button type="button" id="wbe-clear-specific" class="button button-small">
                                    <?php _e('Tout Effacer', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>

                        <div class="wbe-section">
                            <h3><?php _e('Exclure des Dates', Constants::TEXT_DOMAIN); ?></h3>

                            <!-- AJOUTER: Zone d'ajout de date d'exclusion -->
                            <div class="wbe-add-date">
                                <div class="wbe-date-field">
                                    <label for="wbe-add-exclusion-date"><?php _e('Ajouter une date à exclure', Constants::TEXT_DOMAIN); ?></label>
                                    <input type="text" id="wbe-add-exclusion-date" class="wbe-datepicker" placeholder="<?php _e('Cliquez pour choisir une date', Constants::TEXT_DOMAIN); ?>" readonly>
                                    <button type="button" id="wbe-add-exclusion-btn" class="button button-primary">
                                        <?php _e('Ajouter', Constants::TEXT_DOMAIN); ?>
                                    </button>
                                </div>
                                <p class="description"><?php _e('Ajoutez des dates spécifiques où l\'activité ne sera PAS disponible.', Constants::TEXT_DOMAIN); ?></p>
                            </div>

                            <div class="wbe-selected-dates">
                                <h4><?php _e('Dates Exclues', Constants::TEXT_DOMAIN); ?>:</h4>
                                <div id="wbe-exclusions-list" class="wbe-dates-list"></div>
                                <button type="button" id="wbe-clear-exclusions" class="button button-small">
                                    <?php _e('Tout Effacer', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dans la section de l'étape 2 -->
                <div class="wbe-step-actions">
                    <button class="button wbe-prev-step" data-prev="1">
                        <?php _e('← Retour', Constants::TEXT_DOMAIN); ?>
                    </button>
                    <button class="button button-primary wbe-next-step" data-next="3" id="wbe-validate-and-next">
                        <span class="wbe-validation-spinner" style="display: none;">
                            <span class="spinner is-active" style="margin: 0 5px"></span>
                        </span>
                        <span class="wbe-button-text">
                            <?php _e('Suivant: Révision →', Constants::TEXT_DOMAIN); ?>
                        </span>
                    </button>
                </div>

                <!-- Ajouter une zone pour les messages d'erreur -->
                <div id="wbe-step2-errors" style="display: none;"></div>
            </section>

            <!-- STEP 3: Review & Apply -->
            <section class="wbe-step-content" data-step="3">
                <div class="wbe-card">
                    <div class="wbe-card-header">
                        <h2><?php _e('3. Réviser et Appliquer', Constants::TEXT_DOMAIN); ?></h2>
                    </div>
                    <div class="wbe-card-body">
                        <div class="wbe-review-box">
                            <h3><?php _e('Révision des Modifications', Constants::TEXT_DOMAIN); ?></h3>
                            <div id="wbe-review-summary"></div>
                        </div>

                        <div id="wbe-progress-container" style="display: none;">
                            <div class="wbe-progress-header">
                                <span id="wbe-progress-percentage">0%</span>
                                <span id="wbe-time-remaining"></span>
                            </div>
                            <div class="wbe-progress-bar">
                                <div id="wbe-progress-fill" class="wbe-progress-fill"></div>
                            </div>
                            <div class="wbe-progress-details">
                                <span id="wbe-progress-text"></span>
                            </div>
                            <div class="wbe-progress-actions">
                                <button type="button" id="wbe-cancel-process" class="button">
                                    <?php _e('Annuler', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wbe-step-actions">
                    <button class="button wbe-prev-step" data-prev="2">
                        <?php _e('← Retour', Constants::TEXT_DOMAIN); ?>
                    </button>
                    <button class="button button-secondary" id="wbe-preview-btn">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php _e('Prévisualiser', Constants::TEXT_DOMAIN); ?>
                    </button>
                    <button class="button button-primary" id="wbe-apply-btn">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Appliquer aux Produits Sélectionnés', Constants::TEXT_DOMAIN); ?>
                    </button>
                </div>
            </section>

            <div id="wbe-toast-container" class="wbe-toast-container"></div>
        </div>
<?php
    }

    public function add_help_tabs(): void
    {
        $screen = get_current_screen();
        if ($screen->id !== $this->page_hook) {
            return;
        }

        $screen->add_help_tab([
            'id' => 'wbe-overview',
            'title' => __('Aperçu', Constants::TEXT_DOMAIN),
            'content' => '<p>' . __('Utilisez cet outil pour éditer en masse la disponibilité des produits tours.', Constants::TEXT_DOMAIN) . '</p>',
        ]);
    }

    public function add_plugin_action_links(array $links): array
    {
        if (!$this->user_can_access()) {
            return $links;
        }

        $action_links = [
            'settings' => sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=wootour-bulk-edit'),
                __('Edition de Masse', Constants::TEXT_DOMAIN)
            ),
        ];

        return array_merge($action_links, $links);
    }
}
