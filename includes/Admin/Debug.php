<?php

namespace WBE\Admin;

use WBE\Helpers\Security;

/**
 * Page de debug pour inspecter les métadonnées Wootour
 */
class Debug
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_debug_page']);
    }

    public function register_debug_page(): void
    {
        if (!Security::can_manage_woocommerce()) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            'WBE Debug',
            'WBE Debug',
            'manage_woocommerce',
            'wbe-debug',
            [$this, 'render_debug_page']
        );
    }

    public function render_debug_page(): void
    {
        // Vérifier que la classe existe
        if (class_exists('WBE\Services\WootourSyncService')) {
            echo "✅ WootourSyncService chargé";
        } else {
            echo "❌ WootourSyncService introuvable";
        }
        // Produit à tester
        $test_product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 241;

?>
        <div class="wrap">
            <h1>🔍 WBE Debug - Inspection des métadonnées</h1>

            <form method="get" style="margin: 20px 0;">
                <input type="hidden" name="page" value="wbe-debug">
                <label>
                    <strong>ID du produit à inspecter :</strong>
                    <input type="number" name="product_id" value="<?php echo esc_attr($test_product_id); ?>" style="width: 100px;">
                </label>
                <button type="submit" class="button button-primary">Inspecter</button>
            </form>

            <hr>

            <?php
            if ($test_product_id) {
                $this->display_product_inspection($test_product_id);
            }
            ?>
        </div>
        <style>
            .wbe-debug-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                margin: 20px 0;
            }

            .wbe-debug-section h2 {
                margin-top: 0;
                border-bottom: 2px solid #2271b1;
                padding-bottom: 10px;
            }

            .wbe-debug-table {
                width: 100%;
                border-collapse: collapse;
            }

            .wbe-debug-table th,
            .wbe-debug-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }

            .wbe-debug-table th {
                background: #f9f9f9;
                font-weight: bold;
            }

            .wbe-debug-table tr:nth-child(even) {
                background: #f9f9f9;
            }

            .wbe-meta-key {
                font-family: monospace;
                color: #2271b1;
                font-weight: bold;
            }

            .wbe-meta-value {
                font-family: monospace;
                font-size: 12px;
            }

            .wbe-success {
                color: #007017;
                font-weight: bold;
            }

            .wbe-error {
                color: #b32d2e;
                font-weight: bold;
            }
        </style>
    <?php
    }

    private function display_product_inspection(int $product_id): void
    {
        $product = get_post($product_id);

        if (!$product) {
            echo '<div class="notice notice-error"><p>❌ Produit introuvable</p></div>';
            return;
        }

        echo '<div class="notice notice-success"><p>✅ Produit trouvé : <strong>' . esc_html($product->post_title) . '</strong></p></div>';

        // 1. Informations du produit
        $this->section_product_info($product);

        // 2. Toutes les métadonnées
        $this->section_all_meta($product_id);

        // 3. Métadonnées Wootour détectées
        $this->section_wootour_meta($product_id);

        // 4. Test d'écriture
        $this->section_write_test($product_id);

        // 5. Test avec les clés supposées
        $this->section_test_supposed_keys($product_id);
    }

    private function section_product_info(\WP_Post $product): void
    {
    ?>
        <div class="wbe-debug-section">
            <h2>📋 Informations du produit</h2>
            <table class="wbe-debug-table">
                <tr>
                    <th>ID</th>
                    <td><?php echo $product->ID; ?></td>
                </tr>
                <tr>
                    <th>Titre</th>
                    <td><?php echo esc_html($product->post_title); ?></td>
                </tr>
                <tr>
                    <th>Type de post</th>
                    <td><code><?php echo esc_html($product->post_type); ?></code></td>
                </tr>
                <tr>
                    <th>Statut</th>
                    <td><?php echo esc_html($product->post_status); ?></td>
                </tr>
            </table>
        </div>
    <?php
    }

    private function section_all_meta(int $product_id): void
    {
        $all_meta = get_post_meta($product_id);

    ?>
        <div class="wbe-debug-section">
            <h2>📦 Toutes les métadonnées (<?php echo count($all_meta); ?> clés)</h2>
            <table class="wbe-debug-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Clé</th>
                        <th>Valeur</th>
                        <th style="width: 15%;">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_meta as $key => $values): ?>
                        <tr>
                            <td class="wbe-meta-key"><?php echo esc_html($key); ?></td>
                            <td class="wbe-meta-value">
                                <?php
                                $value = $values[0] ?? '';
                                $unserialized = maybe_unserialize($value);

                                if (is_array($unserialized)) {
                                    echo '<pre>' . esc_html(print_r($unserialized, true)) . '</pre>';
                                } else {
                                    echo esc_html(substr($value, 0, 200));
                                    if (strlen($value) > 200) {
                                        echo '...';
                                    }
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html(gettype($unserialized)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php
    }

    private function section_wootour_meta(int $product_id): void
    {
        $all_meta = get_post_meta($product_id);
        $wootour_meta = [];

        foreach ($all_meta as $key => $values) {
            if (stripos($key, 'tour') !== false || stripos($key, 'woo') !== false) {
                $wootour_meta[$key] = $values[0] ?? '';
            }
        }

    ?>
        <div class="wbe-debug-section">
            <h2>🎯 Métadonnées Wootour détectées (<?php echo count($wootour_meta); ?> clés)</h2>

            <?php if (empty($wootour_meta)): ?>
                <p class="wbe-error">❌ Aucune métadonnée Wootour détectée</p>
                <p><em>Ce produit n'utilise peut-être pas Wootour, ou les clés utilisent un préfixe différent.</em></p>
            <?php else: ?>
                <table class="wbe-debug-table">
                    <thead>
                        <tr>
                            <th>Clé</th>
                            <th>Valeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($wootour_meta as $key => $value): ?>
                            <tr>
                                <td class="wbe-meta-key"><?php echo esc_html($key); ?></td>
                                <td class="wbe-meta-value">
                                    <?php
                                    $unserialized = maybe_unserialize($value);
                                    echo '<pre>' . esc_html(print_r($unserialized, true)) . '</pre>';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php
    }

    private function section_write_test(int $product_id): void
    {
        $test_key = '_wbe_test_write_' . time();
        $test_value = 'Test écriture ' . date('Y-m-d H:i:s');

        // Test d'écriture
        $write_result = update_post_meta($product_id, $test_key, $test_value);

        // Test de lecture
        $read_result = get_post_meta($product_id, $test_key, true);

        // Nettoyage
        delete_post_meta($product_id, $test_key);

    ?>
        <div class="wbe-debug-section">
            <h2>✍️ Test d'écriture/lecture</h2>
            <table class="wbe-debug-table">
                <tr>
                    <th>Clé de test</th>
                    <td><code><?php echo esc_html($test_key); ?></code></td>
                </tr>
                <tr>
                    <th>Valeur écrite</th>
                    <td><?php echo esc_html($test_value); ?></td>
                </tr>
                <tr>
                    <th>Résultat écriture</th>
                    <td class="<?php echo $write_result !== false ? 'wbe-success' : 'wbe-error'; ?>">
                        <?php
                        if ($write_result !== false) {
                            echo '✅ SUCCESS';
                        } else {
                            echo '❌ FAILED';
                        }
                        echo ' (retour: ' . var_export($write_result, true) . ')';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Valeur lue</th>
                    <td class="<?php echo $read_result === $test_value ? 'wbe-success' : 'wbe-error'; ?>">
                        <?php
                        if ($read_result === $test_value) {
                            echo '✅ ' . esc_html($read_result);
                        } else {
                            echo '❌ ' . var_export($read_result, true);
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>
    <?php
    }

    private function section_test_supposed_keys(int $product_id): void
    {
        $supposed_keys = [
            '_tour_start_date',
            '_tour_end_date',
            '_tour_available_days',
            '_tour_unavailable_dates',
            '_tour_specific_dates'
        ];

    ?>
        <div class="wbe-debug-section">
            <h2>🔑 Test des clés supposées par WBE</h2>
            <table class="wbe-debug-table">
                <thead>
                    <tr>
                        <th>Clé supposée</th>
                        <th>Existe ?</th>
                        <th>Valeur actuelle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supposed_keys as $key): ?>
                        <?php
                        $value = get_post_meta($product_id, $key, true);
                        $exists = metadata_exists('post', $product_id, $key);
                        ?>
                        <tr>
                            <td class="wbe-meta-key"><?php echo esc_html($key); ?></td>
                            <td class="<?php echo $exists ? 'wbe-success' : 'wbe-error'; ?>">
                                <?php echo $exists ? '✅ OUI' : '❌ NON'; ?>
                            </td>
                            <td class="wbe-meta-value">
                                <?php
                                if ($exists && $value !== '') {
                                    $unserialized = maybe_unserialize($value);
                                    echo '<pre>' . esc_html(print_r($unserialized, true)) . '</pre>';
                                } else {
                                    echo '<em>vide ou inexistant</em>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p><strong>Test de création :</strong></p>
            <?php
            // Tester la création sur la première clé qui n'existe pas
            $test_key = '_tour_start_date';
            $test_value = '2026-12-31';

            $before = get_post_meta($product_id, $test_key, true);
            $write = update_post_meta($product_id, $test_key, $test_value);
            $after = get_post_meta($product_id, $test_key, true);

            // Restaurer l'état initial
            if (empty($before)) {
                delete_post_meta($product_id, $test_key);
            } else {
                update_post_meta($product_id, $test_key, $before);
            }
            ?>
            <table class="wbe-debug-table">
                <tr>
                    <th>Avant test</th>
                    <td><?php echo empty($before) ? '<em>vide</em>' : esc_html($before); ?></td>
                </tr>
                <tr>
                    <th>Tentative d'écriture</th>
                    <td class="<?php echo $write !== false ? 'wbe-success' : 'wbe-error'; ?>">
                        <?php echo $write !== false ? '✅ SUCCESS' : '❌ FAILED'; ?>
                        (retour: <?php echo var_export($write, true); ?>)
                    </td>
                </tr>
                <tr>
                    <th>Après test</th>
                    <td class="<?php echo $after === $test_value ? 'wbe-success' : 'wbe-error'; ?>">
                        <?php
                        if ($after === $test_value) {
                            echo '✅ ' . esc_html($after);
                        } else {
                            echo '❌ ' . var_export($after, true);
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>État restauré</th>
                    <td><?php echo empty($before) ? '<em>supprimé</em>' : '<em>restauré</em>'; ?></td>
                </tr>
            </table>
        </div>
<?php
    }
}
