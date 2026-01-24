<?php
/**
 * Wootour Bulk Editor - Calendar Partial
 * 
 * Template for date selection calendar.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Views
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * @var string $calendar_id Unique ID for the calendar
 * @var string $mode 'specific' or 'exclusion'
 * @var array $selected_dates Pre-selected dates
 */
?>

<div class="wbe-calendar-container" data-calendar-id="<?php echo esc_attr($calendar_id); ?>" data-mode="<?php echo esc_attr($mode); ?>">
    <div class="wbe-calendar-controls">
        <button type="button" class="button button-small wbe-calendar-prev">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
        </button>
        <span class="wbe-calendar-month-year"></span>
        <button type="button" class="button button-small wbe-calendar-next">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </button>
        <button type="button" class="button button-small wbe-calendar-today">
            <?php echo esc_html__('Today', Constants::TEXT_DOMAIN); ?>
        </button>
    </div>
    
    <div class="wbe-calendar-grid">
        <!-- Calendar will be populated by JavaScript -->
        <div class="wbe-calendar-loading">
            <span class="spinner is-active"></span>
        </div>
    </div>
    
    <div class="wbe-calendar-actions">
        <button type="button" class="button button-small wbe-select-range">
            <?php echo esc_html__('Select Range', Constants::TEXT_DOMAIN); ?>
        </button>
        <button type="button" class="button button-small wbe-clear-selection">
            <?php echo esc_html__('Clear Selection', Constants::TEXT_DOMAIN); ?>
        </button>
    </div>
</div>