<?php

namespace WootourBulkEditor\Core;

defined('ABSPATH') || exit;

/**
 * Class Plugin
 * 
 * Contrôleur principal du plugin utilisant le pattern Singleton.
 * Orchestre tous les composants du plugin.
 */
final class Plugin
{
    use Traits\Singleton;

    /**
     * Instance du plugin
     */
    private static ?self $instance = null;

    /**
     * Instance de l'autoloader
     */
    private Autoloader $autoloader;

    /**
     * Indique si le plugin est initialisé
     */
    private bool $initialized = false;

    /**
     * Registre des composants du plugin
     */
    private array $components = [];

    /**
     * Constructeur privé pour le pattern Singleton
     */
    private function __construct()
    {
        // Initialiser l'autoloader
        $this->autoloader = new Autoloader();
    }

    /**
     * Initialiser le plugin
     * 
     * @throws \RuntimeException Si l'initialisation échoue
     * @return self
     */
    public function init(): self
    {
        if ($this->initialized) {
            return $this;
        }

        try {
            Constants::validate_environment();
            $this->autoloader->register();
            $this->maybe_update();
            $this->register_lifecycle_hooks();
            $this->initialize_components();
            $this->register_wordpress_hooks();

            $this->initialized = true;

            error_log('[WootourBulkEditor] Plugin initialized successfully');
        } catch (\Exception $e) {
            error_log('[WootourBulkEditor] Initialization failed: ' . $e->getMessage());
            throw new \RuntimeException('Plugin initialization failed', 0, $e);
        }

        return $this;
    }

    /**
     * Enregistrer les hooks d'activation/désactivation
     */
    private function register_lifecycle_hooks(): void
    {
        register_activation_hook(
            Constants::plugin_dir() . 'wootour-bulk-editor.php',
            [$this, 'activate']
        );

        register_deactivation_hook(
            Constants::plugin_dir() . 'wootour-bulk-editor.php',
            [$this, 'deactivate']
        );
    }

    /**
     * Callback d'activation du plugin
     * 
     * @param bool $network_wide Activation réseau ou non
     */
    public function activate(bool $network_wide = false): void
    {
        // Vérifier les capacités
        if (!current_user_can('activate_plugins')) {
            wp_die('Permissions insuffisantes pour activer le plugin');
        }

        // Configurer les options par défaut si nécessaire
        $this->setup_default_options();

        // Effacer les données en cache
        $this->clear_transients();

        // Logger l'activation
        update_option('wbe_activated_at', current_time('mysql'));
        error_log('[WootourBulkEditor] Plugin activated');
    }

    /**
     * Callback de désactivation du plugin
     */
    public function deactivate(): void
    {
        // Effacer les événements planifiés s'il y en a
        $this->clear_scheduled_events();

        // Logger la désactivation
        error_log('[WootourBulkEditor] Plugin deactivated');
    }

    /**
     * Initialiser les composants du plugin
     */
    private function initialize_components(): void
    {
        // Initialiser dans un ordre spécifique pour gérer les dépendances
        $components = [
            // Services de base en premier
            \WootourBulkEditor\Services\LoggerService::class,

            // Repositories
            \WootourBulkEditor\Repositories\WootourRepository::class,
            \WootourBulkEditor\Repositories\ProductRepository::class,

            // Logique métier
            \WootourBulkEditor\Services\AvailabilityService::class,
            \WootourBulkEditor\Services\BatchProcessor::class,
            \WootourBulkEditor\Services\SecurityService::class,

            // Contrôleurs (dépendent des services)
            \WootourBulkEditor\Controllers\AdminController::class,
            \WootourBulkEditor\Controllers\AjaxController::class,
        ];

        foreach ($components as $component_class) {
            if (class_exists($component_class)) {
                try {
                    // Utiliser getInstance() pour les singletons
                    if (method_exists($component_class, 'getInstance')) {
                        $component = $component_class::getInstance();
                    } else {
                        $component = new $component_class();
                    }

                    // Si le composant a une méthode init(), l'appeler
                    if (method_exists($component, 'init')) {
                        $component->init();
                    }

                    $this->components[$component_class] = $component;
                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[WootourBulkEditor] Failed to initialize component %s: %s',
                        $component_class,
                        $e->getMessage()
                    ));
                }
            } else {
                error_log(sprintf(
                    '[WootourBulkEditor] Component class does not exist: %s',
                    $component_class
                ));
            }
        }
    }

    /**
     * Enregistrer les hooks WordPress (actions/filtres)
     */
    private function register_wordpress_hooks(): void
    {
        // Hooks admin
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Hooks AJAX
        foreach (Constants::AJAX_ACTIONS as $action) {
            add_action("wp_ajax_{$action}", [$this, 'handle_ajax_request']);
            // Si nécessaire pour le frontend (peu probable pour un outil admin)
            // add_action("wp_ajax_nopriv_{$action}", [$this, 'handle_public_ajax']);
        }

        // Ajouter les liens d'action du plugin
        add_filter('plugin_action_links_' . Constants::BASENAME, [$this, 'add_plugin_action_links']);
    }

    /**
     * Enregistrer le menu admin
     */
    public function register_admin_menu(): void
    {
        // Ceci sera géré par AdminController
        // On s'assure juste que le hook est déclenché
        do_action('wbe_register_admin_menu');
    }

    /**
     * Charger les assets admin
     * 
     * @param string $hook_suffix Page admin actuelle
     */
    public function enqueue_admin_assets(string $hook_suffix): void
    {
        // Ceci sera géré par AdminController
        // On s'assure juste que le hook est déclenché
        do_action('wbe_enqueue_admin_assets', $hook_suffix);
    }

    /**
     * Gérer les requêtes AJAX
     */
    public function handle_ajax_request(): void
    {
        // Ceci sera géré par AjaxController
        // On s'assure juste que le hook est déclenché
        do_action('wbe_handle_ajax_request');
    }

    /**
     * Ajouter les liens d'action du plugin
     * 
     * @param array $links Liens d'action existants
     * @return array Liens d'action modifiés
     */
    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wootour-bulk-edit'),
            __('Bulk Edit', Constants::TEXT_DOMAIN)
        );

        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Configurer les options par défaut du plugin
     */
    private function setup_default_options(): void
    {
        $defaults = [
            'wbe_version'               => Constants::VERSION,
            'wbe_batch_size'            => Constants::BATCH_SIZE,
            'wbe_last_cleanup'          => time(),
        ];

        foreach ($defaults as $key => $value) {
            if (!get_option($key)) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Effacer les transients du plugin
     */
    private function clear_transients(): void
    {
        global $wpdb;

        $transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} 
                WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_wbe_%',
                '_transient_timeout_wbe_%'
            )
        );

        foreach ($transients as $transient) {
            $name = str_replace(['_transient_', '_transient_timeout_'], '', $transient);
            delete_transient($name);
        }
    }

    /**
     * Effacer les événements planifiés
     */
    private function clear_scheduled_events(): void
    {
        // Supprimer les tâches cron planifiées
        wp_clear_scheduled_hook('wbe_daily_cleanup');
    }

    /**
     * Obtenir une instance de composant
     * 
     * @template T
     * @param class-string<T> $component_class
     * @return T|null
     */
    public function get_component(string $component_class): ?object
    {
        return $this->components[$component_class] ?? null;
    }

    /**
     * Obtenir tous les composants
     * 
     * @return array
     */
    public function get_components(): array
    {
        return $this->components;
    }

    /**
     * Vérifier si le plugin est initialisé
     * 
     * @return bool
     */
    public function is_initialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Obtenir les informations du plugin pour le débogage
     * 
     * @return array
     */
    public function get_debug_info(): array
    {
        return [
            'version'       => Constants::VERSION,
            'initialized'   => $this->initialized,
            'components'    => array_keys($this->components),
            'environment'   => [
                'php_version'   => PHP_VERSION,
                'wp_version'    => get_bloginfo('version'),
                'woocommerce'   => defined('WC_VERSION') ? WC_VERSION : 'Not active',
                'wootour'       => Constants::get_wootour_version() ?? 'Not active',
            ],
        ];
    }

    private function maybe_update(): void
    {
        $installed_version = get_option('wbe_version', '0.0.0');

        if (version_compare($installed_version, Constants::VERSION, '<')) {
            $this->run_updates($installed_version);
            update_option('wbe_version', Constants::VERSION);
        }
    }

    private function run_updates(string $from_version): void
    {
        if (version_compare($from_version, '2.0.0', '<')) {
            // migrations 2.0.0
        }
    }
}
