<?php
/**
 * Script de Diagnostic WooTour - Détection des Clés de Métadonnées
 * 
 * Ce script analyse vos produits existants pour identifier exactement
 * quelles clés de métadonnées WooTour utilise pour stocker les données
 * de disponibilité.
 * 
 * UTILISATION :
 * 1. Placer ce fichier dans wp-content/plugins/wootour-bulk-editor/
 * 2. Accéder via : votre-site.com/wp-content/plugins/wootour-bulk-editor/diagnostic-wootour.php
 * 3. OU exécuter via WP-CLI : wp eval-file diagnostic-wootour.php
 * 
 * @package     WootourBulkEditor
 * @subpackage  Diagnostics
 * @version     1.0.0
 */

// Sécurité : charger WordPress
if (!defined('ABSPATH')) {
    // Si accès direct (pas via WordPress), charger wp-load.php
    $wp_load_path = dirname(__FILE__, 4) . '/wp-load.php';
    
    if (file_exists($wp_load_path)) {
        require_once $wp_load_path;
    } else {
        die('Erreur : Impossible de charger WordPress. Veuillez accéder à ce script via WP-CLI ou l\'admin WordPress.');
    }
}

// Vérifier les permissions
if (!current_user_can('manage_options')) {
    wp_die('Vous n\'avez pas les permissions nécessaires pour accéder à cette page.');
}

/**
 * Fonction principale de diagnostic
 */
function wbe_diagnostic_wootour_meta_keys() {
    global $wpdb;
    
    echo "<h1>🔍 Diagnostic WooTour - Analyse des Métadonnées</h1>";
    echo "<p><em>Génération du rapport : " . date('d/m/Y H:i:s') . "</em></p>";
    echo "<hr>";
    
    // 1. Vérifier si WooTour est actif
    echo "<h2>1️⃣ Statut de WooTour</h2>";
    
    if (class_exists('EX_WooTour')) {
        echo "✅ <strong>WooTour est actif</strong><br>";
        
        if (defined('WOO_TOUR_PATH')) {
            echo "📁 Chemin : " . WOO_TOUR_PATH . "<br>";
        }
    } else {
        echo "❌ <strong>WooTour n'est PAS actif</strong><br>";
        echo "<em>Diagnostic limité - certaines métadonnées peuvent ne pas être détectées.</em><br>";
    }
    
    echo "<hr>";
    
    // 2. Trouver des produits avec données WooTour
    echo "<h2>2️⃣ Recherche de Produits WooTour</h2>";
    
    $sample_products = $wpdb->get_results("
        SELECT DISTINCT pm.post_id, p.post_title
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'product'
          AND (
              pm.meta_key LIKE '%tour%' 
              OR pm.meta_key LIKE '%wootour%'
              OR pm.meta_key LIKE 'wt_%'
          )
        LIMIT 5
    ");
    
    if (empty($sample_products)) {
        echo "⚠️ <strong>Aucun produit trouvé avec des données WooTour</strong><br>";
        echo "<em>Veuillez d'abord créer au moins un produit avec WooTour et définir sa disponibilité.</em>";
        return;
    }
    
    echo "✅ Trouvé <strong>" . count($sample_products) . "</strong> produit(s) avec données WooTour :<br><ul>";
    foreach ($sample_products as $product) {
        echo "<li>Produit #{$product->post_id} : {$product->post_title}</li>";
    }
    echo "</ul><hr>";
    
    // 3. Analyser TOUTES les métadonnées liées à WooTour
    echo "<h2>3️⃣ Analyse des Métadonnées WooTour</h2>";
    
    $all_wootour_meta_keys = $wpdb->get_results("
        SELECT DISTINCT meta_key, COUNT(*) as usage_count
        FROM {$wpdb->postmeta}
        WHERE (
            meta_key LIKE '%tour%' 
            OR meta_key LIKE '%wootour%'
            OR meta_key LIKE 'wt_%'
            OR meta_key LIKE '%availability%'
            OR meta_key LIKE '%disable%'
            OR meta_key LIKE '%custom%'
            OR meta_key LIKE '%special%'
            OR meta_key LIKE '%weekday%'
            OR meta_key LIKE '%expired%'
        )
        GROUP BY meta_key
        ORDER BY usage_count DESC
    ");
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<thead><tr><th>Clé de Métadonnée</th><th>Nombre d'utilisations</th><th>Importance</th></tr></thead>";
    echo "<tbody>";
    
    foreach ($all_wootour_meta_keys as $meta) {
        $importance = '';
        $key = $meta->meta_key;
        
        // Identifier l'importance de chaque clé
        if (strpos($key, 'availability') !== false) {
            $importance = '🔴 CRITIQUE (Données principales)';
        } elseif (strpos($key, 'disable') !== false || strpos($key, 'wt_disabledate') !== false) {
            $importance = '🟡 IMPORTANT (Dates exclues)';
        } elseif (strpos($key, 'custom') !== false || strpos($key, 'special') !== false || strpos($key, 'wt_customdate') !== false) {
            $importance = '🟡 IMPORTANT (Dates spéciales)';
        } elseif (strpos($key, 'weekday') !== false) {
            $importance = '🟢 Standard (Jours semaine)';
        } elseif (strpos($key, 'start') !== false || strpos($key, 'expired') !== false || strpos($key, 'end') !== false) {
            $importance = '🟢 Standard (Dates début/fin)';
        } else {
            $importance = '⚪ Autre';
        }
        
        echo "<tr>";
        echo "<td><code>{$key}</code></td>";
        echo "<td style='text-align: center;'>{$meta->usage_count}</td>";
        echo "<td>{$importance}</td>";
        echo "</tr>";
    }
    
    echo "</tbody></table><hr>";
    
    // 4. Analyser UN produit en détail
    echo "<h2>4️⃣ Analyse Détaillée d'un Produit</h2>";
    
    $sample_product_id = $sample_products[2]->post_id;
    $sample_product_name = $sample_products[2]->post_title;
    
    echo "<p>Produit analysé : <strong>#{$sample_product_id} - {$sample_product_name}</strong></p>";
    
    $product_meta = get_post_meta($sample_product_id);
    
    // Filtrer seulement les métadonnées pertinentes
    $relevant_meta = [];
    foreach ($product_meta as $key => $values) {
        if (
            strpos($key, 'tour') !== false ||
            strpos($key, 'wt_') !== false ||
            strpos($key, 'availability') !== false ||
            strpos($key, 'disable') !== false ||
            strpos($key, 'custom') !== false ||
            strpos($key, 'special') !== false ||
            strpos($key, 'weekday') !== false ||
            strpos($key, 'expired') !== false
        ) {
            $relevant_meta[$key] = $values;
        }
    }
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<thead><tr><th>Clé</th><th>Valeur</th><th>Type</th></tr></thead>";
    echo "<tbody>";
    
    foreach ($relevant_meta as $key => $values) {
        $value = $values[0] ?? '';
        $type = gettype($value);
        
        // Formater la valeur pour l'affichage
        if (is_array($value)) {
            $display_value = '<pre>' . print_r($value, true) . '</pre>';
        } elseif (is_string($value) && strlen($value) > 200) {
            $display_value = substr($value, 0, 200) . '... <em>(tronqué)</em>';
        } elseif (is_numeric($value) && $value > 1000000000) {
            // Probablement un timestamp UNIX
            $display_value = $value . ' → <strong>' . date('d/m/Y H:i:s', $value) . '</strong>';
        } else {
            $display_value = htmlspecialchars(print_r($value, true));
        }
        
        echo "<tr>";
        echo "<td><code>{$key}</code></td>";
        echo "<td>{$display_value}</td>";
        echo "<td><em>{$type}</em></td>";
        echo "</tr>";
    }
    
    echo "</tbody></table><hr>";
    
    // 5. Recommandations
    echo "<h2>5️⃣ Recommandations</h2>";
    
    echo "<div style='background: #e7f3fe; padding: 15px; border-left: 4px solid #2196F3;'>";
    echo "<h3>📝 Clés à Mettre à Jour dans WootourRepository.php</h3>";
    
    // Analyser les clés trouvées et recommander
    $has_disabled_dates_meta = false;
    $has_custom_dates_meta = false;
    
    foreach ($relevant_meta as $key => $values) {
        if (strpos($key, 'disable') !== false || $key === 'wt_disabledate') {
            $has_disabled_dates_meta = true;
        }
        if (strpos($key, 'custom') !== false || strpos($key, 'special') !== false || $key === 'wt_customdate') {
            $has_custom_dates_meta = true;
        }
    }
    
    if ($has_disabled_dates_meta) {
        echo "<p>✅ <strong>Dates Exclues détectées</strong> - Vérifiez que ces clés sont mises à jour :</p>";
        echo "<ul>";
        foreach ($relevant_meta as $key => $values) {
            if (strpos($key, 'disable') !== false || $key === 'wt_disabledate') {
                echo "<li><code>{$key}</code></li>";
            }
        }
        echo "</ul>";
    } else {
        echo "<p>⚠️ <strong>Aucune métadonnée de dates exclues trouvée</strong></p>";
    }
    
    if ($has_custom_dates_meta) {
        echo "<p>✅ <strong>Dates Spéciales détectées</strong> - Vérifiez que ces clés sont mises à jour :</p>";
        echo "<ul>";
        foreach ($relevant_meta as $key => $values) {
            if (strpos($key, 'custom') !== false || strpos($key, 'special') !== false || $key === 'wt_customdate') {
                echo "<li><code>{$key}</code></li>";
            }
        }
        echo "</ul>";
    } else {
        echo "<p>⚠️ <strong>Aucune métadonnée de dates spéciales trouvée</strong></p>";
    }
    
    echo "</div>";
    
    echo "<hr>";
    
    // 6. Code de test
    echo "<h2>6️⃣ Code de Test Généré</h2>";
    
    echo "<p>Utilisez ce code dans votre fonction <code>updateWootourTimestampMeta()</code> :</p>";
    
    echo "<pre style='background: #f4f4f4; padding: 15px; overflow-x: auto;'>";
    echo htmlspecialchars("
// === DATES EXCLUES (DISABLE DATES) ===
if (isset(\$availability_data['exclusions'])) {
    if (!empty(\$availability_data['exclusions'])) {
        \$disabled_timestamps = [];
        \$disabled_strings = [];
        
        foreach (\$availability_data['exclusions'] as \$date) {
            \$timestamp = strtotime(\$date);
            if (\$timestamp) {
                \$disabled_timestamps[] = \$timestamp;
                \$disabled_strings[] = date('Y-m-d', \$timestamp);
            }
        }
        
        // Mettre à jour TOUTES les clés détectées
");
    
    foreach ($relevant_meta as $key => $values) {
        if (strpos($key, 'disable') !== false || $key === 'wt_disabledate') {
            $value_type = is_array($values[0] ?? null) ? 'timestamps' : 'strings';
            $var_name = ($value_type === 'timestamps') ? '$disabled_timestamps' : '$disabled_strings';
            echo "        update_post_meta(\$product_id, '{$key}', {$var_name});\n";
        }
    }
    
    echo htmlspecialchars("
    }
}

// === DATES SPÉCIALES (SPECIAL/CUSTOM DATES) ===
if (isset(\$availability_data['specific'])) {
    if (!empty(\$availability_data['specific'])) {
        \$custom_timestamps = [];
        \$custom_strings = [];
        
        foreach (\$availability_data['specific'] as \$date) {
            \$timestamp = strtotime(\$date);
            if (\$timestamp) {
                \$custom_timestamps[] = \$timestamp;
                \$custom_strings[] = date('Y-m-d', \$timestamp);
            }
        }
        
        // Mettre à jour TOUTES les clés détectées
");
    
    foreach ($relevant_meta as $key => $values) {
        if (strpos($key, 'custom') !== false || strpos($key, 'special') !== false || $key === 'wt_customdate') {
            $value_type = is_array($values[0] ?? null) ? 'timestamps' : 'strings';
            $var_name = ($value_type === 'timestamps') ? '$custom_timestamps' : '$custom_strings';
            echo "        update_post_meta(\$product_id, '{$key}', {$var_name});\n";
        }
    }
    
    echo htmlspecialchars("
    }
}
");
    
    echo "</pre>";
    
    echo "<hr>";
    echo "<p><strong>✅ Diagnostic terminé !</strong></p>";
    echo "<p><em>Ce rapport contient toutes les informations nécessaires pour corriger l'affichage des dates dans WooTour.</em></p>";
}

// Lancer le diagnostic
wbe_diagnostic_wootour_meta_keys();