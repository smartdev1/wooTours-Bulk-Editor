<?php

/**
 * Plugin Name: Wootour Edition de masses
 * Description: Bulk edit availability for Wootour products without overwriting existing data
 * Version:     2.1.3
 * Author:      Intinct Vertical
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wootour-bulk-editor
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * 
 * @package WootourBulkEditor
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Clear OPcache for this plugin's files
 * 
 * @return array Status of the operation
 */
function wootour_bulk_editor_clear_opcache(): array
{
    $result = [
        'success' => false,
        'message' => '',
        'method' => '',
        'files_cleared' => 0
    ];

    // Check if OPcache is available
    if (!function_exists('opcache_reset') && !function_exists('opcache_invalidate')) {
        $result['message'] = 'OPcache not available on this server';
        return $result;
    }

    // Method 1: Try to reset entire OPcache (requires permissions)
    if (function_exists('opcache_reset')) {
        try {
            if (@opcache_reset()) {
                $result['success'] = true;
                $result['method'] = 'opcache_reset';
                $result['message'] = 'OPcache completely cleared via opcache_reset()';
                error_log('[WootourBulkEditor] OPcache cleared successfully via opcache_reset()');
                return $result;
            }
        } catch (\Throwable $e) {
            error_log('[WootourBulkEditor] opcache_reset() failed: ' . $e->getMessage());
        }
    }

    // Method 2: Invalidate plugin files individually (fallback)
    if (function_exists('opcache_invalidate')) {
        $result['method'] = 'opcache_invalidate';
        $plugin_dir = plugin_dir_path(__FILE__);
        $cleared = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    if (@opcache_invalidate($file->getPathname(), true)) {
                        $cleared++;
                    }
                }
            }

            $result['success'] = true;
            $result['files_cleared'] = $cleared;
            $result['message'] = sprintf('OPcache cleared for %d PHP files via opcache_invalidate()', $cleared);
            error_log(sprintf('[WootourBulkEditor] OPcache cleared for %d files via opcache_invalidate()', $cleared));
        } catch (\Throwable $e) {
            $result['message'] = 'Failed to clear OPcache: ' . $e->getMessage();
            error_log('[WootourBulkEditor] opcache_invalidate() failed: ' . $e->getMessage());
        }
    } else {
        $result['message'] = 'No OPcache clearing method available';
    }

    return $result;
}

/**
 * Clear all caches (OPcache + WordPress object cache)
 * 
 * @return void
 */
function wootour_bulk_editor_clear_all_caches(): void
{
    // Clear OPcache
    $opcache_result = wootour_bulk_editor_clear_opcache();

    // Clear WordPress object cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        error_log('[WootourBulkEditor] WordPress object cache flushed');
    }

    // Clear transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wootour%' OR option_name LIKE '_transient_timeout_wootour%'");

    // Log the complete operation
    error_log(sprintf(
        '[WootourBulkEditor] All caches cleared - OPcache: %s, Method: %s',
        $opcache_result['success'] ? 'Yes' : 'No',
        $opcache_result['method']
    ));
}

/**
 * Plugin activation hook
 * 
 * @return void
 */
function wootour_bulk_editor_activate(): void
{
    error_log('[WootourBulkEditor] Plugin activation started');

    // Clear OPcache on activation
    wootour_bulk_editor_clear_all_caches();

    // Set activation timestamp
    update_option('wootour_bulk_editor_activated_at', time());
    update_option('wootour_bulk_editor_version', '2.1.3');

    error_log('[WootourBulkEditor] Plugin activated successfully');
}
register_activation_hook(__FILE__, 'wootour_bulk_editor_activate');

/**
 * Plugin deactivation hook
 * 
 * @return void
 */
function wootour_bulk_editor_deactivate(): void
{
    error_log('[WootourBulkEditor] Plugin deactivation started');

    // Clear OPcache on deactivation
    wootour_bulk_editor_clear_all_caches();

    error_log('[WootourBulkEditor] Plugin deactivated successfully');
}
register_deactivation_hook(__FILE__, 'wootour_bulk_editor_deactivate');

/**
 * Main plugin bootstrap function
 * 
 * @return WootourBulkEditor\Core\Plugin|null
 */
function wootour_bulk_editor(): ?WootourBulkEditor\Core\Plugin
{
    static $plugin = null;

    if (null === $plugin) {
        try {
            $base_dir = __DIR__;

            // Log initialization
            error_log('[WootourBulkEditor] Initializing plugin from: ' . $base_dir);

            // Load Singleton trait
            $singleton_file = $base_dir . '/includes/Core/Traits/Singleton.php';
            if (file_exists($singleton_file)) {
                require_once $singleton_file;
            }

            // Load Autoloader
            $autoloader_file = $base_dir . '/includes/Core/Autoloader.php';
            if (!file_exists($autoloader_file)) {
                throw new \RuntimeException('Autoloader class not found at: ' . $autoloader_file);
            }
            require_once $autoloader_file;

            // Load Constants
            $constants_file = $base_dir . '/includes/Core/Constants.php';
            if (!file_exists($constants_file)) {
                throw new \RuntimeException('Constants class not found at: ' . $constants_file);
            }
            require_once $constants_file;

            // Load Plugin class
            $plugin_file = $base_dir . '/includes/Core/Plugin.php';
            if (!file_exists($plugin_file)) {
                throw new \RuntimeException('Plugin class not found at: ' . $plugin_file);
            }
            require_once $plugin_file;

            // Initialize plugin
            $plugin = WootourBulkEditor\Core\Plugin::getInstance();
            $plugin->init();

            error_log('[WootourBulkEditor] Plugin initialized successfully');
        } catch (\Throwable $e) {
            // Log error
            error_log(sprintf(
                '[WootourBulkEditor] Failed to initialize: %s in %s:%s',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            // Show admin notice
            if (is_admin()) {
                add_action('admin_notices', function () use ($e) {
?>
                    <div class="notice notice-error">
                        <p>
                            <strong>Wootour Bulk Editor Error:</strong>
                            <?php echo esc_html($e->getMessage()); ?>
                        </p>
                        <p>
                            <em>Check error logs for more details.</em>
                        </p>
                    </div>
        <?php
                });
            }

            $plugin = null;
        }
    }

    return $plugin;
}

add_action('plugins_loaded', 'wootour_bulk_editor', 10);

/**
 * Quick access function to the plugin instance
 * 
 * @return WootourBulkEditor\Core\Plugin|null
 */
function wbe(): ?WootourBulkEditor\Core\Plugin
{
    return wootour_bulk_editor();
}


/**
 * 🔬 DIAGNOSTIC ULTRA-DÉTAILLÉ - WootourRepository (BULK)
 * 
 * À ajouter dans wootour-bulk-editor.php
 */

add_action('admin_init', function () {

    if (!isset($_GET['wbe_deep_diagnostic'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('❌ Permissions insuffisantes');
    }

    // ✅ PRODUITS (TABLEAU)
    $product_ids = [240, 215, 216];

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Diagnostic Profond - Repository (BULK)</title>
        <style>
            body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
            .success { color: #4ec9b0; }
            .error { color: #f48771; }
            .warning { color: #dcdcaa; }
            .info { color: #9cdcfe; }
            pre { background: #2d2d2d; padding: 15px; border-left: 3px solid #007acc; overflow-x: auto; }
            h1, h2, h3 { color: #4ec9b0; }
            hr { border: 1px solid #3e3e3e; }
            table { border-collapse: collapse; margin: 10px 0; }
            td, th { border: 1px solid #555; padding: 6px 10px; }
        </style>
    </head>
    <body>

    <h1>🔬 Diagnostic Profond - WootourRepository (BULK)</h1>
    <p class="info">Produits testés : <?php echo implode(', ', $product_ids); ?></p>
    <hr>

    <?php
    // ==========================================
    // TEST 1 : Chargement du Repository
    // ==========================================
    echo '<h2>1️⃣ Chargement du Repository</h2>';

    try {
        $repo = \WootourBulkEditor\Repositories\WootourRepository::getInstance();
        echo '<p class="success">✅ WootourRepository chargé</p>';
    } catch (\Exception $e) {
        echo '<p class="error">❌ Impossible de charger le Repository : ' . $e->getMessage() . '</p>';
        exit;
    }

    // ==========================================
    // TEST 2 : Récupération availability existante
    // ==========================================
    echo '<h2>2️⃣ Availability existante</h2>';

    foreach ($product_ids as $pid) {
        try {
            echo "<h3>Produit #{$pid}</h3>";
            $existing = $repo->getAvailability($pid);
            echo '<pre>';
            print_r($existing->toArray());
            echo '</pre>';
        } catch (\Exception $e) {
            echo '<p class="error">❌ getAvailability() : ' . $e->getMessage() . '</p>';
        }
    }

    // ==========================================
    // TEST 3 : Données de test
    // ==========================================
    echo '<h2>3️⃣ Données de test</h2>';

    $test_data = [
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'weekdays' => [1, 2, 3, 4, 5],
        'specific' => ['2026-02-14'],
        'exclusions' => ['2026-02-22'],
    ];

    echo '<pre>';
    print_r($test_data);
    echo '</pre>';

    // ==========================================
    // TEST 4 : updateAvailability BULK
    // ==========================================
    echo '<h2>4️⃣ updateAvailability() BULK</h2>';

    $global_result = true;

    foreach ($product_ids as $pid) {

        echo "<h3>Produit #{$pid}</h3>";

        $payload = $test_data;
        $payload['product_id'] = $pid;

        error_log("🔬 BULK updateAvailability | Product #{$pid}");
        error_log(print_r($payload, true));

        $result = $repo->updateAvailability($pid, $payload);

        if ($result) {
            echo '<p class="success">✅ updateAvailability OK</p>';
        } else {
            echo '<p class="error">❌ updateAvailability FAILED</p>';
            $global_result = false;
        }
    }

    // ==========================================
    // TEST 5 : Vérification des métadonnées
    // ==========================================
    echo '<h2>5️⃣ Vérification des métadonnées</h2>';

    $checks = [
        'wt_customdate' => true,
        'wt_disabledate' => true,
        'wt_disable_book' => false,
    ];

    foreach ($product_ids as $pid) {

        echo "<h3>Produit #{$pid}</h3>";
        echo '<table>';
        echo '<tr><th>Meta key</th><th>Valeur</th></tr>';

        foreach ($checks as $key => $single) {
            $value = get_post_meta($pid, $key, $single);

            echo '<tr>';
            echo '<td><code>' . $key . '</code></td>';
            echo '<td>' . (empty($value) ? '<span class="error">VIDE</span>' : '<span class="success">OK</span>') . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    // ==========================================
    // TEST 6 : Nettoyage + écriture manuelle BULK
    // ==========================================
    echo '<h2>6️⃣ Test manuel des métas (BULK)</h2>';

    $ts = strtotime('2026-02-14');

    foreach ($product_ids as $pid) {

        delete_post_meta($pid, 'wt_disabledate');
        delete_post_meta($pid, 'wt_disable_book');
        delete_post_meta($pid, 'wt_customdate');

        update_post_meta($pid, 'wt_customdate', $ts);
        add_post_meta($pid, 'wt_disable_book', $ts);

        echo "<p class='success'>✅ Métas mises à jour pour produit #{$pid}</p>";
    }

    // ==========================================
    // CONCLUSION
    // ==========================================
    echo '<hr>';
    echo '<h2>📋 Conclusion</h2>';

    if ($global_result) {
        echo '<p class="success"><strong>✅ BULK updateAvailability fonctionnel</strong></p>';
    } else {
        echo '<p class="error"><strong>❌ Des erreurs sont survenues (voir logs)</strong></p>';
    }
    ?>

    <hr>
    <h3>🔗 Liens produits</h3>
    <?php foreach ($product_ids as $pid): ?>
        <p>
            <a href="<?php echo admin_url('post.php?post=' . $pid . '&action=edit'); ?>" target="_blank" style="color:#4ec9b0;">
                Ouvrir produit #<?php echo $pid; ?>
            </a>
        </p>
    <?php endforeach; ?>

    </body>
    </html>
    <?php

    exit;
});

// Debug du code WooTour
add_action('admin_init', function() {
    if (isset($_GET['debug_wootour_code'])) {
        $file_path = WP_CONTENT_DIR . '/plugins/woo-tour/inc/admin/Meta-Boxes/classes.fields.php';
        
        if (file_exists($file_path)) {
            $lines = file($file_path);
            
            // Lire autour de la ligne 893
            $start = max(880, 0);
            $end = min(910, count($lines));
            
            echo '<h2>Code WooTour ligne 880-910 :</h2>';
            echo '<pre style="background:#f0f0f0;padding:10px;">';
            for ($i = $start; $i < $end; $i++) {
                echo ($i+1) . ': ' . htmlspecialchars($lines[$i]);
            }
            echo '</pre>';
            
            // Chercher la fonction problématique
            echo '<h2>Recherche de la fonction date() :</h2>';
            foreach ($lines as $num => $line) {
                if (strpos($line, 'date(') !== false) {
                    echo 'Ligne ' . ($num+1) . ': ' . htmlspecialchars($line) . '<br>';
                }
            }
        } else {
            echo 'Fichier non trouvé : ' . $file_path;
        }
        
        exit;
    }
});


/**
 * 🔍 DIAGNOSTIC - Quelle meta key génère wt_start dans le HTML ?
 * 
 * URL: /wp-admin/?wbe_trace_frontend_fields&product_id=215
 */

add_action('admin_init', function() {
    if (!isset($_GET['wbe_trace_frontend_fields'])) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('❌ Permissions insuffisantes');
    }
    
    $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
    
    if (!$product_id) {
        wp_die('Product ID requis. Usage: ?wbe_trace_frontend_fields&product_id=215');
    }
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Diagnostic Frontend Fields - Produit #<?php echo $product_id; ?></title>
        <style>
            body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
            h1, h2 { color: #4ec9b0; }
            .success { color: #4ec9b0; }
            .error { color: #f48771; }
            .warning { color: #dcdcaa; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #2d2d2d; }
            th, td { padding: 12px; text-align: left; border-bottom: 1px solid #3e3e3e; }
            th { background: #252526; color: #4ec9b0; }
            pre { background: #2d2d2d; padding: 15px; border-left: 3px solid #007acc; overflow-x: auto; }
            .btn { display: inline-block; padding: 10px 20px; background: #007acc; color: white; text-decoration: none; border-radius: 4px; margin: 5px; }
        </style>
    </head>
    <body>
        <h1>🔍 Diagnostic - Champs frontend WooTour</h1>
        
        <?php
        $product = wc_get_product($product_id);
        
        if (!$product) {
            echo '<p class="error">❌ Produit introuvable</p>';
            echo '</body></html>';
            exit;
        }
        
        echo '<p class="success">📦 Produit : #' . $product_id . ' - ' . esc_html($product->get_name()) . '</p>';
        
        // ==========================================
        // ÉTAPE 1 : Hook dans WooTour pour voir les champs générés
        // ==========================================
        
        echo '<h2>1️⃣ Simulation de génération des champs cachés</h2>';
        
        // Récupérer TOUTES les métadonnées du produit
        global $wpdb;
        $all_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
             FROM {$wpdb->postmeta} 
             WHERE post_id = %d 
             AND meta_key LIKE 'wt_%'
             ORDER BY meta_key",
            $product_id
        ));
        
        echo '<h3>Métadonnées WooTour disponibles :</h3>';
        echo '<table>';
        echo '<thead><tr><th>Meta Key</th><th>Meta Value (brut)</th><th>Meta Value (formaté)</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($all_meta as $meta) {
            echo '<tr>';
            echo '<td><code>' . esc_html($meta->meta_key) . '</code></td>';
            echo '<td><code>' . esc_html(substr($meta->meta_value, 0, 100)) . '</code></td>';
            echo '<td>';
            
            // Formatter selon le type
            if (is_numeric($meta->meta_value) && $meta->meta_value > 1000000) {
                echo '<strong>' . date('Y-m-d', $meta->meta_value) . '</strong> (timestamp)';
            } elseif (is_serialized($meta->meta_value)) {
                $unserialized = @unserialize($meta->meta_value);
                if (is_array($unserialized)) {
                    echo 'Array (' . count($unserialized) . ' éléments)';
                } else {
                    echo 'Sérialisé';
                }
            } else {
                echo esc_html($meta->meta_value);
            }
            
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        // ==========================================
        // ÉTAPE 2 : Chercher dans le code source WooTour
        // ==========================================
        
        echo '<h2>2️⃣ Recherche dans le code WooTour</h2>';
        
        $wootour_path = WP_PLUGIN_DIR . '/wootour/';
        
        if (file_exists($wootour_path)) {
            echo '<p class="success">✅ WooTour trouvé dans : ' . $wootour_path . '</p>';
            
            // Chercher les fichiers qui génèrent wt_start
            $search_patterns = [
                'name="wt_start"',
                'wt_start',
                'booking_start',
                'tour_start',
            ];
            
            echo '<h3>Recherche des patterns dans le code :</h3>';
            
            $found_files = [];
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($wootour_path)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    
                    foreach ($search_patterns as $pattern) {
                        if (stripos($content, $pattern) !== false) {
                            $found_files[$file->getPathname()][] = $pattern;
                        }
                    }
                }
            }
            
            if (!empty($found_files)) {
                echo '<table>';
                echo '<thead><tr><th>Fichier</th><th>Patterns trouvés</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($found_files as $file => $patterns) {
                    echo '<tr>';
                    echo '<td><code>' . str_replace($wootour_path, '', $file) . '</code></td>';
                    echo '<td>' . implode(', ', array_unique($patterns)) . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
            } else {
                echo '<p class="warning">⚠️ Aucun fichier trouvé avec ces patterns</p>';
            }
            
        } else {
            echo '<p class="error">❌ Répertoire WooTour non trouvé</p>';
        }
        
        // ==========================================
        // ÉTAPE 3 : Produit de référence WooTour natif
        // ==========================================
        
        echo '<h2>3️⃣ Comparaison avec un produit WooTour natif</h2>';
        
        // Trouver un autre produit WooTour
        $reference_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = 'wt_start' 
             AND post_id != %d 
             LIMIT 1",
            $product_id
        ));
        
        if ($reference_id) {
            echo '<p class="success">✅ Produit de référence : #' . $reference_id . '</p>';
            
            // Comparer les métadonnées
            $ref_meta = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value 
                 FROM {$wpdb->postmeta} 
                 WHERE post_id = %d 
                 AND meta_key LIKE 'wt_%'
                 ORDER BY meta_key",
                $reference_id
            ));
            
            echo '<h3>Métadonnées du produit de référence :</h3>';
            echo '<table>';
            echo '<thead><tr><th>Meta Key</th><th>Produit #' . $product_id . '</th><th>Produit #' . $reference_id . ' (référence)</th><th>Status</th></tr></thead>';
            echo '<tbody>';
            
            $ref_meta_map = [];
            foreach ($ref_meta as $meta) {
                $ref_meta_map[$meta->meta_key] = $meta->meta_value;
            }
            
            $current_meta_map = [];
            foreach ($all_meta as $meta) {
                $current_meta_map[$meta->meta_key] = $meta->meta_value;
            }
            
            // Fusionner toutes les clés
            $all_keys = array_unique(array_merge(
                array_keys($current_meta_map),
                array_keys($ref_meta_map)
            ));
            
            sort($all_keys);
            
            foreach ($all_keys as $key) {
                $current_val = $current_meta_map[$key] ?? null;
                $ref_val = $ref_meta_map[$key] ?? null;
                
                echo '<tr>';
                echo '<td><code>' . esc_html($key) . '</code></td>';
                
                // Valeur actuelle
                echo '<td>';
                if ($current_val === null) {
                    echo '<span class="error">MANQUANT</span>';
                } elseif (is_numeric($current_val) && $current_val > 1000000) {
                    echo date('Y-m-d', $current_val);
                } else {
                    echo esc_html(substr($current_val, 0, 50));
                }
                echo '</td>';
                
                // Valeur référence
                echo '<td>';
                if ($ref_val === null) {
                    echo '<span class="error">MANQUANT</span>';
                } elseif (is_numeric($ref_val) && $ref_val > 1000000) {
                    echo date('Y-m-d', $ref_val);
                } else {
                    echo esc_html(substr($ref_val, 0, 50));
                }
                echo '</td>';
                
                // Status
                echo '<td>';
                if ($current_val === null && $ref_val !== null) {
                    echo '<span class="error">❌ MANQUANT</span>';
                } elseif ($current_val !== null && $ref_val === null) {
                    echo '<span class="warning">⚠️ Extra</span>';
                } elseif ($current_val === $ref_val) {
                    echo '<span class="success">✅ Identique</span>';
                } else {
                    echo '<span class="warning">⚠️ Différent</span>';
                }
                echo '</td>';
                
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            
        } else {
            echo '<p class="warning">⚠️ Aucun produit de référence trouvé</p>';
        }
        
        // ==========================================
        // ACTIONS
        // ==========================================
        
        echo '<h2>📋 Actions recommandées</h2>';
        
        echo '<div>';
        echo '<a href="' . get_permalink($product_id) . '" class="btn" target="_blank">Voir sur le frontend</a>';
        echo '<a href="' . admin_url('post.php?post=' . $product_id . '&action=edit') . '" class="btn" target="_blank">Éditer dans WooCommerce</a>';
        
        if ($reference_id) {
            echo '<a href="' . get_permalink($reference_id) . '" class="btn" target="_blank">Voir produit référence sur le frontend</a>';
        }
        
        echo '</div>';
        
        ?>
    </body>
    </html>
    <?php
    
    exit;
});

add_action('admin_init', function() {
    if (isset($_GET['test_final_solution_all_dates'])) {
        $product_id = intval($_GET['product_id'] ?? 0);
        
        // Toutes nos dates
        $exclusions = ['2026-03-20', '2026-03-21', '2026-03-23'];
        $specific = ['2026-03-17', '2026-03-18'];
        
        // Sauvegarder
        delete_post_meta($product_id, 'wt_disabledate');
        delete_post_meta($product_id, 'wt_disable_book');
        delete_post_meta($product_id, 'wt_customdate');
        
        // wt_disabledate - première date seulement
        if (!empty($exclusions)) {
            $timestamp = strtotime($exclusions[0]);
            update_post_meta($product_id, 'wt_disabledate', $timestamp);
        }
        
        // wt_disable_book - toutes les dates
        foreach ($exclusions as $date) {
            $timestamp = strtotime($date);
            if ($timestamp) {
                add_post_meta($product_id, 'wt_disable_book', $timestamp);
            }
        }
        
        // wt_customdate - première date seulement
        if (!empty($specific)) {
            $timestamp = strtotime($specific[0]);
            update_post_meta($product_id, 'wt_customdate', $timestamp);
        }
        
        echo '<h2>Solution Finale Testée</h2>';
        echo '<p>✅ wt_disabledate (première date): ' . get_post_meta($product_id, 'wt_disabledate', true) . '</p>';
        echo '<p>✅ wt_disable_book (toutes dates): ' . print_r(get_post_meta($product_id, 'wt_disable_book', false), true) . '</p>';
        echo '<p>✅ wt_customdate (première date): ' . get_post_meta($product_id, 'wt_customdate', true) . '</p>';
        
        echo '<p><a href="' . admin_url('post.php?post=' . $product_id . '&action=edit') . '" target="_blank">➡️ TESTER dans WooTour</a></p>';
        
        exit;
    }
});

/**
 * Add admin notice after plugin update to inform about cache clearing
 * 
 * @return void
 */
add_action('admin_notices', function () {
    // Only show to administrators
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check if we just updated
    $current_version = get_option('wootour_bulk_editor_version');
    $plugin_version = '2.1.3';

    if (version_compare($current_version, $plugin_version, '<')) {
        // Clear caches after update
        wootour_bulk_editor_clear_all_caches();

        // Update version
        update_option('wootour_bulk_editor_version', $plugin_version);

        // Show notice
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong>Wootour Bulk Editor:</strong> Plugin mis à jour vers la version <?php echo esc_html($plugin_version); ?>.
                Les caches ont été vidés automatiquement.
            </p>
        </div>
        <?php
    }
});

/**
 * Add a debug action to manually clear caches (for development)
 * This can be triggered via URL: /wp-admin/?wbe_clear_cache=1&wbe_nonce=xxx
 */
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('admin_init', function () {
        if (isset($_GET['wbe_clear_cache']) && current_user_can('manage_options')) {
            // Verify nonce for security
            if (!isset($_GET['wbe_nonce']) || !wp_verify_nonce($_GET['wbe_nonce'], 'wbe_clear_cache')) {
                wp_die('Invalid security token');
            }

            // Clear all caches
            $result = wootour_bulk_editor_clear_opcache();
            wootour_bulk_editor_clear_all_caches();

            // Show result
            add_action('admin_notices', function () use ($result) {
        ?>
                <div class="notice notice-success">
                    <p><strong>Cache Cleared!</strong></p>
                    <ul>
                        <li>OPcache: <?php echo $result['success'] ? '✅ Cleared' : '❌ Failed'; ?></li>
                        <li>Method: <?php echo esc_html($result['method']); ?></li>
                        <li>Message: <?php echo esc_html($result['message']); ?></li>
                    </ul>
                </div>
<?php
            });
        }
    });
}

/**
 * Display OPcache status in plugin row (for admins)
 */
add_filter('plugin_row_meta', function ($plugin_meta, $plugin_file) {
    if (plugin_basename(__FILE__) === $plugin_file && current_user_can('manage_options')) {
        $opcache_status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        if ($opcache_status !== false) {
            $cache_url = wp_nonce_url(
                admin_url('?wbe_clear_cache=1'),
                'wbe_clear_cache',
                'wbe_nonce'
            );

            $plugin_meta[] = sprintf(
                '<a href="%s" style="color: #d63638;">🗑️ Clear OPcache</a>',
                esc_url($cache_url)
            );
        }
    }

    return $plugin_meta;
}, 10, 2);
