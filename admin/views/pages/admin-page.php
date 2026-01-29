<?php
/**
 * Wootour Bulk Editor - Admin Page Template
 * 
 * Main admin interface for bulk editing Wootour product availability.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Views
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

// Security check
if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
    wp_die(__('You do not have permission to access this page.', Constants::TEXT_DOMAIN));
}

// Get plugin data
$plugin_data = get_plugin_data(Constants::plugin_dir() . 'wootour-bulk-editor.php');
?>

<div class="wrap wbe-admin-wrap">
    <!-- Header -->
    <header class="wbe-header">
        <h1 class="wp-heading-inline">
            <span class="wbe-icon">📅</span>
            <?php echo esc_html__('Wootour Bulk Editor', Constants::TEXT_DOMAIN); ?>
        </h1>
        
        <div class="wbe-header-actions">
            <button type="button" class="button button-secondary wbe-help-toggle">
                <span class="dashicons dashicons-editor-help"></span>
                <?php echo esc_html__('Help', Constants::TEXT_DOMAIN); ?>
            </button>
            <button type="button" class="button button-secondary wbe-view-logs">
                <span class="dashicons dashicons-list-view"></span>
                <?php echo esc_html__('View Logs', Constants::TEXT_DOMAIN); ?>
            </button>
        </div>
    </header>

    <!-- Stats Bar -->
    <div class="wbe-stats-bar">
        <div class="wbe-stat">
            <span class="wbe-stat-label"><?php echo esc_html__('Total Products', Constants::TEXT_DOMAIN); ?>:</span>
            <span class="wbe-stat-value" id="wbe-total-products">0</span>
        </div>
        <div class="wbe-stat">
            <span class="wbe-stat-label"><?php echo esc_html__('Selected', Constants::TEXT_DOMAIN); ?>:</span>
            <span class="wbe-stat-value" id="wbe-selected-count">0</span>
        </div>
        <div class="wbe-stat">
            <span class="wbe-stat-label"><?php echo esc_html__('With Wootour', Constants::TEXT_DOMAIN); ?>:</span>
            <span class="wbe-stat-value" id="wbe-wootour-count">0</span>
        </div>
        <div class="wbe-stat">
            <span class="wbe-stat-label"><?php echo esc_html__('Version', Constants::TEXT_DOMAIN); ?>:</span>
            <span class="wbe-stat-value"><?php echo esc_html($plugin_data['Version']); ?></span>
        </div>
    </div>

    <!-- Main Content -->
    <div class="wbe-main-content">
        <!-- Left Column: Product Selection -->
        <div class="wbe-column wbe-column-left">
            <div class="wbe-card">
                <div class="wbe-card-header">
                    <h2><?php echo esc_html__('1. Select Products', Constants::TEXT_DOMAIN); ?></h2>
                </div>
                
                <div class="wbe-card-body">
                    <!-- Category Filter -->
                    <div class="wbe-section">
                        <h3><?php echo esc_html__('Filter by Category', Constants::TEXT_DOMAIN); ?></h3>
                        <div class="wbe-category-filter">
                            <select id="wbe-category-select" class="wbe-select">
                                <option value="0"><?php echo esc_html__('All Categories', Constants::TEXT_DOMAIN); ?></option>
                                <!-- Categories will be populated by JavaScript -->
                            </select>
                            <button type="button" id="wbe-load-category" class="button button-secondary">
                                <?php echo esc_html__('Load Products', Constants::TEXT_DOMAIN); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Product Search -->
                    <div class="wbe-section">
                        <h3><?php echo esc_html__('Search Products', Constants::TEXT_DOMAIN); ?></h3>
                        <div class="wbe-search-box">
                            <input type="text" 
                                   id="wbe-product-search" 
                                   class="wbe-search-input" 
                                   placeholder="<?php echo esc_attr__('Search by name or SKU...', Constants::TEXT_DOMAIN); ?>">
                            <button type="button" id="wbe-search-btn" class="button button-secondary">
                                <span class="dashicons dashicons-search"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Product List -->
                    <div class="wbe-section">
                        <div class="wbe-product-list-header">
                            <h3><?php echo esc_html__('Products', Constants::TEXT_DOMAIN); ?></h3>
                            <div class="wbe-product-actions">
                                <button type="button" id="wbe-select-all" class="button button-small">
                                    <?php echo esc_html__('Select All', Constants::TEXT_DOMAIN); ?>
                                </button>
                                <button type="button" id="wbe-deselect-all" class="button button-small">
                                    <?php echo esc_html__('Deselect All', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="wbe-product-list-container">
                            <div id="wbe-product-list" class="wbe-product-list">
                                <!-- Products will be loaded here -->
                                <div class="wbe-loading">
                                    <span class="spinner is-active"></span>
                                    <?php echo esc_html__('Loading products...', Constants::TEXT_DOMAIN); ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Pagination -->
                        <div class="wbe-pagination">
                            <button type="button" id="wbe-prev-page" class="button button-small" disabled>
                                &larr; <?php echo esc_html__('Previous', Constants::TEXT_DOMAIN); ?>
                            </button>
                            <span class="wbe-page-info">
                                <?php echo esc_html__('Page', Constants::TEXT_DOMAIN); ?> 
                                <span id="wbe-current-page">1</span> 
                                <?php echo esc_html__('of', Constants::TEXT_DOMAIN); ?> 
                                <span id="wbe-total-pages">1</span>
                            </span>
                            <button type="button" id="wbe-next-page" class="button button-small" disabled>
                                <?php echo esc_html__('Next', Constants::TEXT_DOMAIN); ?> &rarr;
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Date Selection & Actions -->
        <div class="wbe-column wbe-column-right">
            <!-- Date Selection Card -->
            <div class="wbe-card">
                <div class="wbe-card-header">
                    <h2><?php echo esc_html__('2. Set Availability', Constants::TEXT_DOMAIN); ?></h2>
                </div>
                
                <div class="wbe-card-body">
                    <!-- Date Range -->
                    <div class="wbe-section">
                        <h3><?php echo esc_html__('Date Range', Constants::TEXT_DOMAIN); ?></h3>
                        <div class="wbe-date-range">
                            <div class="wbe-date-field">
                                <label for="wbe-start-date"><?php echo esc_html__('Start Date', Constants::TEXT_DOMAIN); ?></label>
                                <input type="text" 
                                       id="wbe-start-date" 
                                       class="wbe-datepicker" 
                                       placeholder="DD/MM/YYYY">
                                <button type="button" class="button button-small wbe-clear-date" data-target="wbe-start-date">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                            <div class="wbe-date-field">
                                <label for="wbe-end-date"><?php echo esc_html__('End Date', Constants::TEXT_DOMAIN); ?></label>
                                <input type="text" 
                                       id="wbe-end-date" 
                                       class="wbe-datepicker" 
                                       placeholder="DD/MM/YYYY">
                                <button type="button" class="button button-small wbe-clear-date" data-target="wbe-end-date">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Weekdays -->
                    <div class="wbe-section">
                        <h3><?php echo esc_html__('Available Weekdays', Constants::TEXT_DOMAIN); ?></h3>
                        <div class="wbe-weekdays">
                            <?php 
                            $weekdays = [
                                'monday'    => __('Monday', Constants::TEXT_DOMAIN),
                                'tuesday'   => __('Tuesday', Constants::TEXT_DOMAIN),
                                'wednesday' => __('Wednesday', Constants::TEXT_DOMAIN),
                                'thursday'  => __('Thursday', Constants::TEXT_DOMAIN),
                                'friday'    => __('Friday', Constants::TEXT_DOMAIN),
                                'saturday'  => __('Saturday', Constants::TEXT_DOMAIN),
                                'sunday'    => __('Sunday', Constants::TEXT_DOMAIN),
                            ];
                            
                            foreach ($weekdays as $key => $label): ?>
                                <label class="wbe-checkbox-label">
                                    <input type="checkbox" 
                                           class="wbe-weekday-checkbox" 
                                           name="weekdays[<?php echo esc_attr($key); ?>]" 
                                           value="on">
                                    <span><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Specific Dates -->
                    <div class="wbe-section">
                        <h3><?php echo esc_html__('Specific Dates', Constants::TEXT_DOMAIN); ?></h3>
                        <div class="wbe-specific-dates">
                            <div id="wbe-specific-calendar" class="wbe-calendar"></div>
                            <div class="wbe-selected-dates">
                                <h4><?php echo esc_html__('Selected Dates', Constants::TEXT_DOMAIN); ?>:</h4>
                                <div id="wbe-specific-dates-list" class="wbe-dates-list">
                                    <!-- Selected dates will appear here -->
                                </div>
                                <button type="button" id="wbe-clear-specific" class="button button-small">
                                    <?php echo esc_html__('Clear All', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Exclusions -->
                    <div class="wbe-section">
                        <h3><?php echo esc_html__('Exclude Dates', Constants::TEXT_DOMAIN); ?></h3>
                        <div class="wbe-exclusions">
                            <div id="wbe-exclusion-calendar" class="wbe-calendar"></div>
                            <div class="wbe-selected-dates">
                                <h4><?php echo esc_html__('Excluded Dates', Constants::TEXT_DOMAIN); ?>:</h4>
                                <div id="wbe-exclusions-list" class="wbe-dates-list">
                                    <!-- Excluded dates will appear here -->
                                </div>
                                <button type="button" id="wbe-clear-exclusions" class="button button-small">
                                    <?php echo esc_html__('Clear All', Constants::TEXT_DOMAIN); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions Card -->
            <div class="wbe-card wbe-actions-card">
                <div class="wbe-card-header">
                    <h2><?php echo esc_html__('3. Apply Changes', Constants::TEXT_DOMAIN); ?></h2>
                </div>
                
                <div class="wbe-card-body">
                    <!-- Preview Button -->
                    <button type="button" id="wbe-preview-btn" class="button button-secondary button-large">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php echo esc_html__('Preview Changes', Constants::TEXT_DOMAIN); ?>
                    </button>

                    <!-- Apply Button -->
                    <button type="button" id="wbe-apply-btn" class="button button-primary button-large">
                        <span class="dashicons dashicons-update"></span>
                        <?php echo esc_html__('Apply Changes', Constants::TEXT_DOMAIN); ?>
                    </button>

                    <!-- Reset Button -->
                    <button type="button" id="wbe-reset-btn" class="button button-link">
                        <?php echo esc_html__('Reset Form', Constants::TEXT_DOMAIN); ?>
                    </button>

                    <!-- Progress Bar (hidden by default) -->
                    <div id="wbe-progress-container" class="wbe-progress-container" style="display: none;">
                        <div class="wbe-progress-header">
                            <h4><?php echo esc_html__('Processing...', Constants::TEXT_DOMAIN); ?></h4>
                            <span id="wbe-progress-percentage">0%</span>
                        </div>
                        <div class="wbe-progress-bar">
                            <div id="wbe-progress-fill" class="wbe-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="wbe-progress-details">
                            <span id="wbe-progress-text"><?php echo esc_html__('Initializing...', Constants::TEXT_DOMAIN); ?></span>
                            <span id="wbe-time-remaining"></span>
                        </div>
                        <div class="wbe-progress-actions">
                            <button type="button" id="wbe-cancel-process" class="button button-small">
                                <?php echo esc_html__('Cancel', Constants::TEXT_DOMAIN); ?>
                            </button>
                            <button type="button" id="wbe-resume-process" class="button button-small" style="display: none;">
                                <?php echo esc_html__('Resume', Constants::TEXT_DOMAIN); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal (hidden) -->
    <div id="wbe-preview-modal" class="wbe-modal" style="display: none;">
        <div class="wbe-modal-content">
            <div class="wbe-modal-header">
                <h2><?php echo esc_html__('Preview Changes', Constants::TEXT_DOMAIN); ?></h2>
                <button type="button" class="wbe-modal-close">&times;</button>
            </div>
            <div class="wbe-modal-body">
                <div id="wbe-preview-content">
                    <!-- Preview content will be loaded here -->
                </div>
            </div>
            <div class="wbe-modal-footer">
                <button type="button" class="button button-secondary wbe-modal-close">
                    <?php echo esc_html__('Close', Constants::TEXT_DOMAIN); ?>
                </button>
                <button type="button" id="wbe-confirm-apply" class="button button-primary">
                    <?php echo esc_html__('Confirm & Apply', Constants::TEXT_DOMAIN); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Logs Modal (hidden) -->
    <div id="wbe-logs-modal" class="wbe-modal" style="display: none;">
        <div class="wbe-modal-content wbe-modal-large">
            <div class="wbe-modal-header">
                <h2><?php echo esc_html__('Operation Logs', Constants::TEXT_DOMAIN); ?></h2>
                <button type="button" class="wbe-modal-close">&times;</button>
            </div>
            <div class="wbe-modal-body">
                <div id="wbe-logs-content">
                    <!-- Logs will be loaded here -->
                </div>
            </div>
            <div class="wbe-modal-footer">
                <button type="button" class="button button-secondary" id="wbe-clear-logs">
                    <?php echo esc_html__('Clear Logs', Constants::TEXT_DOMAIN); ?>
                </button>
                <button type="button" class="button button-primary wbe-modal-close">
                    <?php echo esc_html__('Close', Constants::TEXT_DOMAIN); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Help Modal (hidden) -->
    <div id="wbe-help-modal" class="wbe-modal" style="display: none;">
        <div class="wbe-modal-content wbe-modal-large">
            <div class="wbe-modal-header">
                <h2><?php echo esc_html__('Help & Documentation', Constants::TEXT_DOMAIN); ?></h2>
                <button type="button" class="wbe-modal-close">&times;</button>
            </div>
            <div class="wbe-modal-body">
                <!-- Help content will be loaded from WordPress help tabs -->
                <div id="wbe-help-content">
                    <?php
                    // Reuse WordPress help tab content
                    $screen = get_current_screen();
                    if ($screen) {
                        $help_tabs = $screen->get_help_tabs();
                        if (!empty($help_tabs)) {
                            echo '<div class="wbe-help-tabs">';
                            foreach ($help_tabs as $tab) {
                                echo '<div class="wbe-help-tab">';
                                echo '<h3>' . esc_html($tab['title']) . '</h3>';
                                echo wp_kses_post($tab['content']);
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
            <div class="wbe-modal-footer">
                <button type="button" class="button button-primary wbe-modal-close">
                    <?php echo esc_html__('Close', Constants::TEXT_DOMAIN); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Error Toast Container -->
    <div id="wbe-toast-container" class="wbe-toast-container"></div>
</div>

<!-- Hidden fields for JavaScript -->
<input type="hidden" id="wbe-ajax-url" value="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
<input type="hidden" id="wbe-nonce" value="<?php echo esc_attr(wp_create_nonce('wbe_ajax_nonce')); ?>">
<input type="hidden" id="wbe-current-user-id" value="<?php echo get_current_user_id(); ?>">