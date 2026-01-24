<?php

/**
 * Wootour Bulk Editor - Security Service
 * 
 * Centralized security checks and protections.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Services
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Services;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Core\Traits\Singleton;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class SecurityService
 * 
 * Gère toutes les fonctionnalités liées à la sécurité
 */
final class SecurityService
{
    use Singleton;

    /**
     * Initialiser le service
     */
    public function init(): void
    {
        // Les hooks de sécurité peuvent être ajoutés ici si nécessaire
    }

    /**
     * Vérifier si l'utilisateur a la capacité requise
     * 
     * @throws \RuntimeException Si l'utilisateur n'a pas la capacité
     */
    public function check_user_capability(): void
    {
        $has_cap = false;

        foreach (Constants::REQUIRED_CAPS as $role => $cap) {
            if (current_user_can($cap)) {
                $has_cap = true;
                break;
            }
        }

        if (!$has_cap) {
            throw new \RuntimeException(
                'Vous n\'avez pas la permission d\'effectuer cette action.',
                Constants::ERROR_CODES['permission_denied']
            );
        }
    }

    /**
     * Vérifier le nonce
     * 
     * @param string $nonce Le nonce à vérifier
     * @param string $context Le contexte (ajax, admin_page, bulk_edit)
     * @return bool
     */
    public function verify_nonce(string $nonce, string $context): bool
    {
        $action = Constants::NONCE_ACTIONS[$context] ?? '';

        if (empty($action)) {
            return false;
        }

        return wp_verify_nonce($nonce, $action) !== false;
    }

    /**
     * Créer un nonce
     * 
     * @param string $context Le contexte (ajax, admin_page, bulk_edit)
     * @return string
     */
    public function create_nonce(string $context): string
    {
        $action = Constants::NONCE_ACTIONS[$context] ?? 'wbe_general';
        return wp_create_nonce($action);
    }

    public function canManageProducts(): bool
    {
        foreach (Constants::REQUIRED_CAPS as $role => $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vérifier le referer AJAX
     * 
     * @return bool
     */
    public function check_referer(): bool
    {
        return check_ajax_referer(Constants::NONCE_ACTIONS['ajax'], false, false) !== false;
    }

    /**
     * Ajouter des en-têtes de sécurité
     */
    public function add_security_headers(): void
    {
        // Prévenir le clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prévenir le MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Protection XSS basique (navigateurs anciens)
        header('X-XSS-Protection: 1; mode=block');

        // Politique de référent
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy (basique)
        if (defined('WP_DEBUG') && !WP_DEBUG) {
            $csp = [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://ajax.googleapis.com https://cdnjs.cloudflare.com",
                "style-src 'self' 'unsafe-inline' https://ajax.googleapis.com",
                "img-src 'self' data: https:",
                "font-src 'self'",
                "connect-src 'self'",
            ];

            header("Content-Security-Policy: " . implode('; ', $csp));
        }
    }

    /**
     * Nettoyer un tableau d'entiers
     * 
     * @param array $values Tableau de valeurs
     * @return array Tableau d'entiers nettoyés
     */
    public function sanitize_int_array(array $values): array
    {
        return array_map('intval', array_filter($values, 'is_numeric'));
    }

    /**
     * Nettoyer une chaîne de date
     * 
     * @param string $date Date à nettoyer
     * @return string Date au format Y-m-d ou chaîne vide
     */
    public function sanitize_date(string $date): string
    {
        $date = trim($date);

        if (empty($date)) {
            return '';
        }

        // Essayer de parser comme date
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Nettoyer un tableau de dates
     * 
     * @param array $dates Tableau de dates
     * @return array Tableau de dates nettoyées
     */
    public function sanitize_date_array(array $dates): array
    {
        $sanitized = [];

        foreach ($dates as $date) {
            $clean = $this->sanitize_date($date);
            if (!empty($clean)) {
                $sanitized[] = $clean;
            }
        }

        return array_unique($sanitized);
    }

    /**
     * Valider que les IDs de produits existent et sont accessibles
     * 
     * @param array $product_ids Tableau d'IDs de produits
     * @return array Tableau d'IDs valides
     */
    public function validate_product_ids(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        global $wpdb;

        // Nettoyer les IDs
        $product_ids = $this->sanitize_int_array($product_ids);

        if (empty($product_ids)) {
            return [];
        }

        // Vérifier l'existence et l'accessibilité
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $valid_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID 
            FROM {$wpdb->posts} p 
            WHERE p.ID IN ($placeholders) 
            AND p.post_type = 'product' 
            AND p.post_status IN ('publish', 'private', 'draft')",
            ...$product_ids
        ));

        return array_map('intval', $valid_ids);
    }

    /**
     * Enregistrer un événement de sécurité
     * 
     * @param string $event Type d'événement
     * @param array $data Données supplémentaires
     */
    public function log_security_event(string $event, array $data = []): void
    {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'event' => sanitize_text_field($event),
            'user_id' => get_current_user_id(),
            'user_ip' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent(),
            'data' => $data,
        ];

        // Stocker dans la table options (rotation automatique)
        $option_name = Constants::LOG_OPTION_PREFIX . 'security_' . date('Y_m');
        $logs = get_option($option_name, []);
        $logs[] = $log_entry;

        // Garder seulement les 100 dernières entrées
        if (count($logs) > Constants::LOG_MAX_ENTRIES) {
            $logs = array_slice($logs, -Constants::LOG_MAX_ENTRIES);
        }

        update_option($option_name, $logs, false);
    }

    /**
     * Obtenir l'IP du client de manière sécurisée
     * 
     * @return string
     */
    private function get_client_ip(): string
    {
        $ip = 'unknown';

        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
        }

        return $ip ?: 'unknown';
    }

    /**
     * Obtenir le user agent de manière sécurisée
     * 
     * @return string
     */
    private function get_user_agent(): string
    {
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            return substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255);
        }

        return 'unknown';
    }

    /**
     * Détecter et prévenir les tentatives de force brute
     * 
     * @param string $action Action à vérifier
     * @param int $limit Nombre maximum de tentatives
     * @param int $window Fenêtre de temps en secondes
     * @return bool True si autorisé, false si limite dépassée
     */
    public function check_rate_limit(string $action, int $limit = 10, int $window = 60): bool
    {
        $user_id = get_current_user_id();
        $transient_key = 'wbe_rate_limit_' . sanitize_key($action) . '_' . $user_id;
        $attempts = get_transient($transient_key) ?: 0;

        if ($attempts >= $limit) {
            $this->log_security_event('rate_limit_exceeded', [
                'action' => $action,
                'attempts' => $attempts,
                'user_id' => $user_id,
            ]);

            return false;
        }

        $attempts++;
        set_transient($transient_key, $attempts, $window);

        return true;
    }

    /**
     * Valider et nettoyer les données de formulaire
     * 
     * @param array $data Données à nettoyer
     * @return array Données nettoyées
     */
    public function sanitize_form_data(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_form_data($value);
            } elseif (is_string($value)) {
                // Nettoyage différent selon le type de champ
                if (in_array($key, ['start_date', 'end_date']) || strpos($key, 'date') !== false) {
                    $sanitized[$key] = $this->sanitize_date($value);
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            } elseif (is_int($value)) {
                $sanitized[$key] = intval($value);
            } elseif (is_bool($value)) {
                $sanitized[$key] = (bool) $value;
            } else {
                // Pour les autres types, on les garde tels quels
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Vérifier si une requête AJAX est valide
     * 
     * @param string $action Action AJAX attendue
     * @return bool
     * @throws \RuntimeException Si la requête n'est pas valide
     */
    public function verify_ajax_request(string $action): bool
    {
        // Vérifier que c'est une requête AJAX
        if (!wp_doing_ajax()) {
            throw new \RuntimeException('Requête AJAX invalide');
        }

        // Vérifier le nonce
        if (!$this->check_referer()) {
            $this->log_security_event('invalid_nonce', [
                'action' => $action,
            ]);
            throw new \RuntimeException('Nonce invalide');
        }

        // Vérifier les capacités utilisateur
        $this->check_user_capability();

        // Vérifier le rate limiting
        if (!$this->check_rate_limit($action, 50, 60)) {
            throw new \RuntimeException('Trop de requêtes. Veuillez patienter.');
        }

        return true;
    }

    /**
     * Nettoyer les logs anciens
     */
    public function cleanup_old_logs(): void
    {
        global $wpdb;

        $cutoff_date = date('Y_m', strtotime('-' . Constants::LOG_RETENTION_DAYS . ' days'));

        $options = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            AND option_name < %s",
            Constants::LOG_OPTION_PREFIX . 'security_%',
            Constants::LOG_OPTION_PREFIX . 'security_' . $cutoff_date
        ));

        foreach ($options as $option) {
            delete_option($option);
        }
    }
}
