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
 * 🎯 Diagnostic Ciblé - Pourquoi les métadonnées ne sont pas écrites ?
 * 
 * PROBLÈME : Les données arrivent au serveur mais ne s'écrivent pas dans WooTours
 * 
 * Ajouter ce code dans wootour-bulk-editor.php temporairement
 */

// =====================================
// TEST 1 : Vérifier que updateAvailability() est appelé
// =====================================

add_action('init', function() {
    // Hook pour tracer l'appel à updateAvailability
    add_filter('wbe_before_repository_update', function($product_id, $data) {
        error_log('');
        error_log('========================================');
        error_log('🔵 REPOSITORY updateAvailability() APPELÉ');
        error_log('========================================');
        error_log('Product ID: ' . $product_id);
        error_log('Données reçues:');
        error_log('  start_date: ' . ($data['start_date'] ?? 'NOT SET'));
        error_log('  end_date: ' . ($data['end_date'] ?? 'NOT SET'));
        error_log('  weekdays: ' . print_r($data['weekdays'] ?? [], true));
        error_log('  specific: ' . print_r($data['specific'] ?? 'NOT SET', true));
        error_log('  exclusions: ' . print_r($data['exclusions'] ?? 'NOT SET', true));
        error_log('========================================');
        error_log('');
        
        return $product_id;
    }, 10, 2);
});

// =====================================
// TEST 2 : Vérifier les UPDATE/ADD meta
// =====================================

add_action('update_post_metadata', function($check, $object_id, $meta_key, $meta_value) {
    if (in_array($meta_key, ['wt_disabledate', 'wt_customdate', 'wt_disable_book'])) {
        error_log('');
        error_log('🟡 TENTATIVE UPDATE META');
        error_log('  Key: ' . $meta_key);
        error_log('  Product: ' . $object_id);
        error_log('  Value Type: ' . gettype($meta_value));
        error_log('  Value: ' . print_r($meta_value, true));
        error_log('');
    }
    return $check;
}, 10, 4);

add_action('add_post_metadata', function($check, $object_id, $meta_key, $meta_value) {
    if ($meta_key === 'wt_disable_book') {
        error_log('');
        error_log('🟢 TENTATIVE ADD META');
        error_log('  Key: ' . $meta_key);
        error_log('  Product: ' . $object_id);
        error_log('  Value Type: ' . gettype($meta_value));
        error_log('  Value: ' . print_r($meta_value, true));
        error_log('');
    }
    return $check;
}, 10, 4);

add_action('updated_post_meta', function($meta_id, $object_id, $meta_key, $meta_value) {
    if (in_array($meta_key, ['wt_disabledate', 'wt_customdate', 'wt_disable_book'])) {
        error_log('');
        error_log('✅ META UPDATED AVEC SUCCÈS');
        error_log('  Key: ' . $meta_key);
        error_log('  Product: ' . $object_id);
        error_log('  Value: ' . print_r($meta_value, true));
        error_log('');
    }
}, 10, 4);

add_action('added_post_meta', function($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key === 'wt_disable_book') {
        error_log('');
        error_log('✅ META ADDED AVEC SUCCÈS');
        error_log('  Key: ' . $meta_key);
        error_log('  Product: ' . $object_id);
        error_log('  Value: ' . print_r($meta_value, true));
        error_log('');
    }
}, 10, 4);

// =====================================
// TEST 3 : Vérification immédiate après traitement
// =====================================

add_action('woocommerce_update_product', function($product_id) {
    static $checked = [];
    
    // Éviter les boucles infinies
    if (isset($checked[$product_id])) {
        return;
    }
    $checked[$product_id] = true;
    
    error_log('');
    error_log('========================================');
    error_log('🔍 VÉRIFICATION POST-UPDATE Produit #' . $product_id);
    error_log('========================================');
    
    $checks = [
        'wt_disabledate' => true,
        'wt_disable_book' => false,
        'wt_customdate' => true,
    ];
    
    foreach ($checks as $key => $single) {
        $value = get_post_meta($product_id, $key, $single);
        
        error_log('');
        error_log('Meta: ' . $key);
        
        if ($single) {
            if (empty($value)) {
                error_log('  ❌ VIDE ou ABSENT');
            } else {
                error_log('  ✅ Présent: ' . $value);
                if (is_numeric($value)) {
                    error_log('  📅 Date: ' . date('Y-m-d', $value));
                }
            }
        } else {
            if (empty($value)) {
                error_log('  ❌ AUCUNE VALEUR');
            } else {
                error_log('  ✅ ' . count($value) . ' valeur(s)');
                foreach ($value as $v) {
                    if (is_numeric($v)) {
                        error_log('    - ' . $v . ' (' . date('Y-m-d', $v) . ')');
                    }
                }
            }
        }
    }
    
    error_log('========================================');
    error_log('');
}, 999);

// =====================================
// TEST MANUEL DIRECT
// =====================================

/**
 * Test direct d'écriture de métadonnées
 * URL: /wp-admin/?wbe_test_direct_write&product_id=240
 */
add_action('admin_init', function() {
    if (isset($_GET['wbe_test_direct_write'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Permissions insuffisantes');
        }
        
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        
        if (!$product_id) {
            wp_die('Product ID requis. Usage: ?wbe_test_direct_write&product_id=240');
        }
        
        echo '<h1>🧪 Test Direct d\'Écriture - Produit #' . $product_id . '</h1>';
        
        // Dates de test
        $test_specific = '2026-01-31';
        $test_exclusion = '2026-02-15';
        
        echo '<h2>📋 Dates de test</h2>';
        echo '<ul>';
        echo '<li>Date spécifique: ' . $test_specific . '</li>';
        echo '<li>Date exclusion: ' . $test_exclusion . '</li>';
        echo '</ul>';
        
        // Nettoyage
        echo '<h2>🧹 Nettoyage des anciennes données</h2>';
        delete_post_meta($product_id, 'wt_disabledate');
        delete_post_meta($product_id, 'wt_disable_book');
        delete_post_meta($product_id, 'wt_customdate');
        echo '<p>✅ Métadonnées supprimées</p>';
        
        // Test 1: wt_customdate (date spécifique)
        echo '<h2>📝 Test 1: wt_customdate</h2>';
        $ts_specific = strtotime($test_specific);
        $result1 = update_post_meta($product_id, 'wt_customdate', $ts_specific);
        
        if ($result1) {
            echo '<p>✅ update_post_meta() retourné: TRUE</p>';
        } else {
            echo '<p>⚠️ update_post_meta() retourné: FALSE (peut être normal si la valeur n\'a pas changé)</p>';
        }
        
        $verify1 = get_post_meta($product_id, 'wt_customdate', true);
        if ($verify1) {
            echo '<p>✅ Vérification: ' . $verify1 . ' (' . date('Y-m-d', $verify1) . ')</p>';
        } else {
            echo '<p>❌ ÉCHEC: La métadonnée n\'a PAS été enregistrée</p>';
        }
        
        // Test 2: wt_disabledate (première exclusion)
        echo '<h2>📝 Test 2: wt_disabledate</h2>';
        $ts_exclusion = strtotime($test_exclusion);
        $result2 = update_post_meta($product_id, 'wt_disabledate', $ts_exclusion);
        
        if ($result2) {
            echo '<p>✅ update_post_meta() retourné: TRUE</p>';
        } else {
            echo '<p>⚠️ update_post_meta() retourné: FALSE</p>';
        }
        
        $verify2 = get_post_meta($product_id, 'wt_disabledate', true);
        if ($verify2) {
            echo '<p>✅ Vérification: ' . $verify2 . ' (' . date('Y-m-d', $verify2) . ')</p>';
        } else {
            echo '<p>❌ ÉCHEC: La métadonnée n\'a PAS été enregistrée</p>';
        }
        
        // Test 3: wt_disable_book (toutes les exclusions)
        echo '<h2>📝 Test 3: wt_disable_book (add_post_meta)</h2>';
        $result3 = add_post_meta($product_id, 'wt_disable_book', $ts_exclusion);
        
        if ($result3) {
            echo '<p>✅ add_post_meta() retourné: ' . $result3 . ' (meta_id)</p>';
        } else {
            echo '<p>❌ add_post_meta() retourné: FALSE</p>';
        }
        
        $verify3 = get_post_meta($product_id, 'wt_disable_book', false);
        if (!empty($verify3)) {
            echo '<p>✅ Vérification: ' . count($verify3) . ' valeur(s)</p>';
            foreach ($verify3 as $v) {
                echo '<p>  - ' . $v . ' (' . date('Y-m-d', $v) . ')</p>';
            }
        } else {
            echo '<p>❌ ÉCHEC: Aucune valeur trouvée</p>';
        }
        
        // Vérification finale
        echo '<h2>🔍 Vérification dans WooTours</h2>';
        echo '<p><a href="' . admin_url('post.php?post=' . $product_id . '&action=edit') . '" class="button button-primary" target="_blank">Ouvrir dans WooCommerce</a></p>';
        echo '<p><em>Les dates devraient maintenant apparaître dans les champs "Disable date" et "Special Date" de WooTours</em></p>';
        
        // Instructions
        echo '<h2>📋 Résultats attendus</h2>';
        echo '<ul>';
        echo '<li>Si TOUS les tests sont ✅ : Le problème est dans votre Repository</li>';
        echo '<li>Si certains tests sont ❌ : Problème de permissions ou de base de données</li>';
        echo '<li>Si les dates N\'apparaissent PAS dans WooTours : Mauvais format ou mauvaise meta key</li>';
        echo '</ul>';
        
        exit;
    }
});

/**
 * INSTRUCTIONS D'UTILISATION
 * ==========================
 * 
 * 1. Coller ce code dans wootour-bulk-editor.php
 * 
 * 2. Activer WP_DEBUG dans wp-config.php
 * 
 * 3. Tester l'écriture directe:
 *    /wp-admin/?wbe_test_direct_write&product_id=240
 * 
 * 4. Vérifier dans WooTours si les dates apparaissent
 * 
 * 5. Essayer l'édition en masse depuis votre plugin
 * 
 * 6. Vérifier /wp-content/debug.log pour voir:
 *    - Si updateAvailability() est appelé
 *    - Si les tentatives d'update_post_meta sont faites
 *    - Si elles réussissent
 * 
 * SCÉNARIOS POSSIBLES:
 * ====================
 * 
 * Scénario A: Le test direct fonctionne mais pas le plugin
 * → Problème dans WootourRepository::updateWootourTimestampMeta()
 * → Solution: Vérifier que la méthode est bien appelée
 * 
 * Scénario B: Le test direct ne fonctionne pas
 * → Problème de permissions WordPress ou de base de données
 * → Vérifier les permissions de l'utilisateur
 * 
 * Scénario C: Les métadonnées sont écrites mais invisibles dans WooTours
 * → Mauvais format de données ou mauvaise meta key
 * → WooTours attend peut-être un format spécifique
 */


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
