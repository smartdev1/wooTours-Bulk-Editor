<?php

namespace WBE\Services;

class LoggerService
{
    private const LOG_OPTION = 'wbe_activity_log';
    private const MAX_LOG_ENTRIES = 100;

    /**
     * Log une mise à jour en masse avec détails complets
     * 
     * @param int $total_products
     * @param int $success_count
     * @param int $error_count
     * @param array $changes Modifications appliquées
     * @param array $details Détails par produit
     * @return bool
     */
    public static function log_bulk_update_detailed(
        int $total_products,
        int $success_count,
        int $error_count,
        array $changes,
        array $details
    ): bool {
        return self::log_action('bulk_update', [
            'total_products' => $total_products,
            'success' => $success_count,
            'errors' => $error_count,
            'changes_requested' => $changes,
            'details' => array_slice($details, 0, 10), // Limiter à 10 pour ne pas surcharger
            'summary' => self::generate_summary($details)
        ]);
    }

    /**
     * Génère un résumé des modifications
     * 
     * @param array $details
     * @return array
     */
    private static function generate_summary(array $details): array
    {
        $summary = [
            'total_modified' => 0,
            'fields_modified' => [],
            'products' => []
        ];

        foreach ($details as $detail) {
            if ($detail['status'] === 'success') {
                $summary['total_modified']++;
                $summary['products'][] = [
                    'id' => $detail['product_id'],
                    'name' => $detail['product_name']
                ];

                if (isset($detail['modifications'])) {
                    foreach ($detail['modifications'] as $mod) {
                        if ($mod['changed']) {
                            $field = $mod['field'];
                            if (!isset($summary['fields_modified'][$field])) {
                                $summary['fields_modified'][$field] = 0;
                            }
                            $summary['fields_modified'][$field]++;
                        }
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Enregistre une action dans le log
     */
    public static function log_action(string $action, array $details = []): bool
    {
        $entry = [
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'user_login' => wp_get_current_user()->user_login,
            'action' => $action,
            'details' => $details
        ];

        $log = get_option(self::LOG_OPTION, []);
        array_unshift($log, $entry);

        if (count($log) > self::MAX_LOG_ENTRIES) {
            $log = array_slice($log, 0, self::MAX_LOG_ENTRIES);
        }

        return update_option(self::LOG_OPTION, $log);
    }

    /**
     * Log une réinitialisation
     */
    public static function log_reset(int $product_id, bool $success): bool
    {
        return self::log_action('reset_product', [
            'product_id' => $product_id,
            'product_name' => get_the_title($product_id),
            'success' => $success
        ]);
    }

    /**
     * Récupère les logs récents
     */
    public static function get_recent_logs(int $limit = 20): array
    {
        $log = get_option(self::LOG_OPTION, []);
        return array_slice($log, 0, $limit);
    }

    /**
     * Efface tous les logs
     */
    public static function clear_logs(): bool
    {
        return delete_option(self::LOG_OPTION);
    }

    /**
     * Log une erreur produit
     */
    public static function log_product_error(int $product_id, string $error_message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE] Erreur produit #%d (%s) : %s',
                $product_id,
                get_the_title($product_id),
                $error_message
            ));
        }
    }
}