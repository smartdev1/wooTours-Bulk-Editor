<?php

namespace WBE\Services;

use WBE\Woo\ProductRepository;
use WBE\Wootour\AvailabilityUpdater;
use WBE\Wootour\MetaRepository;

/**
 * Processeur de traitement en masse avec logging détaillé
 */
class BulkProcessor
{
    private const BATCH_SIZE = 50;

    /**
     * Traite une mise à jour en masse avec détails complets
     * 
     * @param array $product_ids IDs des produits à traiter
     * @param array $changes Modifications à appliquer (normalisées)
     * @return array Rapport d'exécution détaillé
     */
    public static function process_bulk_update(array $product_ids, array $changes): array
    {
        $report = [
            'total' => count($product_ids),
            'success' => 0,
            'errors' => 0,
            'error_details' => [],
            'processed_ids' => [],
            'failed_ids' => [],
            'details' => [], 
            'changes_applied' => $changes, 
        ];

        $product_ids = array_values(array_filter(
            is_array($product_ids)
                ? $product_ids
                : (is_string($product_ids)
                    ? json_decode($product_ids, true) ?? []
                    : []
                ),
            fn($id) => is_numeric($id) && (int)$id > 0
        ));

        if (empty($product_ids)) {
            $report['error_details'][] = "Aucun produit sélectionné";
            return $report;
        }

        if (empty($changes)) {
            $report['error_details'][] = "Aucune modification spécifiée";
            return $report;
        }

        // Traitement par lots
        $batches = ProductRepository::split_into_batches($product_ids, self::BATCH_SIZE);

        foreach ($batches as $batch_index => $batch) {
            $batch_result = self::process_batch_detailed($batch, $changes);

            $report['success'] += $batch_result['success'];
            $report['errors'] += $batch_result['errors'];
            $report['error_details'] = array_merge($report['error_details'], $batch_result['error_details']);
            $report['processed_ids'] = array_merge($report['processed_ids'], $batch_result['processed_ids']);
            $report['failed_ids'] = array_merge($report['failed_ids'], $batch_result['failed_ids']);
            $report['details'] = array_merge($report['details'], $batch_result['details']); // NOUVEAU

            if ($batch_index < count($batches) - 1) {
                usleep(100000);
            }
        }

        // Log détaillé de l'opération
        LoggerService::log_bulk_update_detailed(
            $report['total'],
            $report['success'],
            $report['errors'],
            $changes,
            $report['details']
        );

        return $report;
    }

    /**
     * Traite un lot de produits avec détails complets
     * 
     * @param array $product_ids IDs du lot
     * @param array $changes Modifications à appliquer
     * @return array Résultat détaillé du lot
     */
    private static function process_batch_detailed(array $product_ids, array $changes): array
    {
        $result = [
            'success' => 0,
            'errors' => 0,
            'error_details' => [],
            'processed_ids' => [],
            'failed_ids' => [],
            'details' => []
        ];

        foreach ($product_ids as $product_id) {
            try {
                // Vérifier que le produit existe
                if (!ProductRepository::product_exists($product_id)) {
                    throw new \Exception("Produit introuvable");
                }

                // Récupérer le nom du produit
                $product = get_post($product_id);
                $product_name = $product ? $product->post_title : "Produit #$product_id";

                // Récupérer l'état AVANT modification
                $before = MetaRepository::get_all_availability($product_id);

                // Vérifier les conflits
                $conflicts = ValidationService::check_conflicts($product_id, $changes);
                if (!empty($conflicts)) {
                    throw new \Exception("Conflits : " . implode(', ', $conflicts));
                }

                // Appliquer les modifications
                $success = AvailabilityUpdater::update_availability($product_id, $changes);

                if (!$success) {
                    throw new \Exception("Échec de la mise à jour");
                }

                // Récupérer l'état APRÈS modification
                $after = MetaRepository::get_all_availability($product_id);

                // Construire le détail de modification
                $modification_detail = self::build_modification_detail(
                    $product_id,
                    $product_name,
                    $changes,
                    $before,
                    $after
                );

                $result['success']++;
                $result['processed_ids'][] = $product_id;
                $result['details'][] = $modification_detail;
            } catch (\Exception $e) {
                $result['errors']++;
                $result['failed_ids'][] = $product_id;

                $error_message = "$product_name : {$e->getMessage()}";
                $result['error_details'][] = $error_message;

                // Ajouter aussi aux détails pour traçabilité
                $result['details'][] = [
                    'product_id' => $product_id,
                    'product_name' => get_the_title($product_id),
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];

                LoggerService::log_product_error($product_id, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Construit un détail complet de modification pour un produit
     * 
     * @param int $product_id
     * @param string $product_name
     * @param array $changes Modifications demandées
     * @param array $before État avant
     * @param array $after État après
     * @return array
     */
    private static function build_modification_detail(
        int $product_id,
        string $product_name,
        array $changes,
        array $before,
        array $after
    ): array {
        $detail = [
            'product_id' => $product_id,
            'product_name' => $product_name,
            'status' => 'success',
            'modifications' => []
        ];

        // Analyser chaque type de modification
        foreach ($changes as $key => $value) {
            $modification = [
                'field' => $key,
                'label' => self::get_field_label($key),
                'requested_value' => $value,
                'before' => $before[$key] ?? null,
                'after' => $after[$key] ?? null,
                'changed' => false
            ];

            // Déterminer si la valeur a réellement changé
            $modification['changed'] = self::has_value_changed($before[$key] ?? null, $after[$key] ?? null);

            $detail['modifications'][] = $modification;
        }

        return $detail;
    }

    /**
     * Obtient le label lisible d'un champ
     * 
     * @param string $field
     * @return string
     */
    private static function get_field_label(string $field): string
    {
        $labels = [
            'start_date' => 'Date de début',
            'end_date' => 'Date de fin',
            'available_days' => 'Jours disponibles',
            'unavailable_dates' => 'Dates exclues',
            'specific_dates' => 'Dates spécifiques'
        ];

        return $labels[$field] ?? $field;
    }

    /**
     * Vérifie si une valeur a changé
     * 
     * @param mixed $before
     * @param mixed $after
     * @return bool
     */
    private static function has_value_changed($before, $after): bool
    {
        // Normaliser les valeurs pour comparaison
        $before_normalized = is_array($before) ? $before : [$before];
        $after_normalized = is_array($after) ? $after : [$after];

        return $before_normalized !== $after_normalized;
    }

    /**
     * Réinitialise les disponibilités avec logging détaillé
     * 
     * @param array $product_ids
     * @return array Rapport d'exécution
     */
    public static function process_reset(array $product_ids): array
    {
        $report = [
            'total' => count($product_ids),
            'success' => 0,
            'errors' => 0,
            'error_details' => [],
            'details' => []
        ];

        foreach ($product_ids as $product_id) {
            try {
                if (!ProductRepository::product_exists($product_id)) {
                    throw new \Exception("Produit introuvable");
                }

                $product_name = get_the_title($product_id);
                $before = MetaRepository::get_all_availability($product_id);

                $success = AvailabilityUpdater::reset_availability($product_id);

                if (!$success) {
                    throw new \Exception("Échec de la réinitialisation");
                }

                $report['success']++;
                $report['details'][] = [
                    'product_id' => $product_id,
                    'product_name' => $product_name,
                    'status' => 'success',
                    'action' => 'reset',
                    'data_removed' => $before
                ];

                LoggerService::log_reset($product_id, true);
            } catch (\Exception $e) {
                $report['errors']++;
                $report['error_details'][] = "$product_name : {$e->getMessage()}";
                $report['details'][] = [
                    'product_id' => $product_id,
                    'product_name' => get_the_title($product_id),
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];

                LoggerService::log_reset($product_id, false);
                LoggerService::log_product_error($product_id, $e->getMessage());
            }
        }

        return $report;
    }

    /**
     * Estime le temps de traitement
     * 
     * @param int $product_count
     * @return array
     */
    public static function estimate_processing_time(int $product_count): array
    {
        $estimated_seconds = ceil($product_count * 0.05);
        $batch_count = ceil($product_count / self::BATCH_SIZE);

        return [
            'estimated_seconds' => $estimated_seconds,
            'batch_count' => $batch_count,
            'batch_size' => self::BATCH_SIZE
        ];
    }
}
