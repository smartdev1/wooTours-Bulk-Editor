<?php
/**
 * Wootour Bulk Editor - Availability Form Partial
 * 
 * Template for the availability editing form.
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
 * @var array $changes Current form changes
 * @var array $weekday_names Weekday names for display
 * @var array $selected_dates Currently selected dates
 */
?>

<div class="wbe-availability-form">
    <!-- Date Range Section -->
    <div class="wbe-form-section">
        <h3 class="wbe-form-section-title">
            <span class="dashicons dashicons-calendar"></span>
            <?php echo esc_html__('Date Range', Constants::TEXT_DOMAIN); ?>
        </h3>
        
        <div class="wbe-form-fields">
            <div class="wbe-form-field">
                <label for="wbe-start-date" class="wbe-form-label">
                    <?php echo esc_html__('Start Date', Constants::TEXT_DOMAIN); ?>
                    <span class="wbe-required">*</span>
                </label>
                <div class="wbe-input-group">
                    <input type="text" 
                           id="wbe-start-date" 
                           name="start_date" 
                           class="wbe-date-input" 
                           value="<?php echo esc_attr($changes['start_date'] ?? ''); ?>"
                           placeholder="DD/MM/YYYY"
                           data-validate="date">
                    <button type="button" class="wbe-clear-input" data-target="wbe-start-date">
                        <span class="dashicons dashicons-no"></span>
                    </button>
                </div>
                <div class="wbe-field-description">
                    <?php echo esc_html__('Leave empty to keep existing start date', Constants::TEXT_DOMAIN); ?>
                </div>
            </div>
            
            <div class="wbe-form-field">
                <label for="wbe-end-date" class="wbe-form-label">
                    <?php echo esc_html__('End Date', Constants::TEXT_DOMAIN); ?>
                    <span class="wbe-required">*</span>
                </label>
                <div class="wbe-input-group">
                    <input type="text" 
                           id="wbe-end-date" 
                           name="end_date" 
                           class="wbe-date-input" 
                           value="<?php echo esc_attr($changes['end_date'] ?? ''); ?>"
                           placeholder="DD/MM/YYYY"
                           data-validate="date">
                    <button type="button" class="wbe-clear-input" data-target="wbe-end-date">
                        <span class="dashicons dashicons-no"></span>
                    </button>
                </div>
                <div class="wbe-field-description">
                    <?php echo esc_html__('Leave empty to keep existing end date', Constants::TEXT_DOMAIN); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Weekdays Section -->
    <div class="wbe-form-section">
        <h3 class="wbe-form-section-title">
            <span class="dashicons dashicons-calendar-alt"></span>
            <?php echo esc_html__('Available Days of Week', Constants::TEXT_DOMAIN); ?>
        </h3>
        
        <div class="wbe-form-description">
            <?php echo esc_html__('Select which days of the week should be available. Leave all unchecked to keep existing weekdays.', Constants::TEXT_DOMAIN); ?>
        </div>
        
        <div class="wbe-weekdays-grid">
            <?php 
            $weekday_map = [
                0 => ['key' => 'sunday', 'label' => __('Sunday', Constants::TEXT_DOMAIN)],
                1 => ['key' => 'monday', 'label' => __('Monday', Constants::TEXT_DOMAIN)],
                2 => ['key' => 'tuesday', 'label' => __('Tuesday', Constants::TEXT_DOMAIN)],
                3 => ['key' => 'wednesday', 'label' => __('Wednesday', Constants::TEXT_DOMAIN)],
                4 => ['key' => 'thursday', 'label' => __('Thursday', Constants::TEXT_DOMAIN)],
                5 => ['key' => 'friday', 'label' => __('Friday', Constants::TEXT_DOMAIN)],
                6 => ['key' => 'saturday', 'label' => __('Saturday', Constants::TEXT_DOMAIN)],
            ];
            
            $selected_weekdays = $changes['weekdays'] ?? [];
            
            foreach ($weekday_map as $day_number => $day_info): 
                $is_checked = in_array($day_number, $selected_weekdays);
            ?>
                <div class="wbe-weekday-option">
                    <input type="checkbox" 
                           id="wbe-weekday-<?php echo esc_attr($day_info['key']); ?>" 
                           name="weekdays[<?php echo esc_attr($day_info['key']); ?>]" 
                           value="1" 
                           class="wbe-weekday-checkbox" 
                           <?php echo $is_checked ? 'checked' : ''; ?>
                           data-weekday-number="<?php echo esc_attr($day_number); ?>">
                    <label for="wbe-weekday-<?php echo esc_attr($day_info['key']); ?>" 
                           class="wbe-weekday-label">
                        <span class="wbe-weekday-checkbox-custom"></span>
                        <span class="wbe-weekday-name"><?php echo esc_html($day_info['label']); ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="wbe-weekday-actions">
            <button type="button" class="button button-small wbe-select-weekdays" data-select="weekdays">
                <?php echo esc_html__('Select Weekdays', Constants::TEXT_DOMAIN); ?>
            </button>
            <button type="button" class="button button-small wbe-select-weekdays" data-select="weekend">
                <?php echo esc_html__('Select Weekend', Constants::TEXT_DOMAIN); ?>
            </button>
            <button type="button" class="button button-small wbe-select-weekdays" data-select="all">
                <?php echo esc_html__('Select All', Constants::TEXT_DOMAIN); ?>
            </button>
            <button type="button" class="button button-small wbe-select-weekdays" data-select="none">
                <?php echo esc_html__('Clear All', Constants::TEXT_DOMAIN); ?>
            </button>
        </div>
    </div>

    <!-- Specific Dates Section -->
    <div class="wbe-form-section">
        <h3 class="wbe-form-section-title">
            <span class="dashicons dashicons-star-filled"></span>
            <?php echo esc_html__('Specific Dates', Constants::TEXT_DOMAIN); ?>
        </h3>
        
        <div class="wbe-form-description">
            <?php echo esc_html__('Select specific dates that should be available. These override date ranges and weekdays.', Constants::TEXT_DOMAIN); ?>
        </div>
        
        <div class="wbe-specific-dates-container">
            <!-- Calendar will be loaded here by JavaScript -->
            <div id="wbe-specific-calendar-container" class="wbe-calendar-container">
                <div class="wbe-calendar-loading">
                    <span class="spinner is-active"></span>
                    <?php echo esc_html__('Loading calendar...', Constants::TEXT_DOMAIN); ?>
                </div>
            </div>
            
            <div class="wbe-selected-dates">
                <h4 class="wbe-selected-dates-title">
                    <?php echo esc_html__('Selected Specific Dates', Constants::TEXT_DOMAIN); ?>
                    <span class="wbe-selected-count" id="wbe-specific-count">0</span>
                </h4>
                
                <div id="wbe-specific-dates-list" class="wbe-dates-list">
                    <?php if (!empty($selected_dates['specific'])): ?>
                        <?php foreach ($selected_dates['specific'] as $date): ?>
                            <div class="wbe-date-tag" data-date="<?php echo esc_attr($date); ?>">
                                <span class="wbe-date-text"><?php echo esc_html($date); ?></span>
                                <button type="button" class="wbe-remove-date" data-date="<?php echo esc_attr($date); ?>" data-type="specific">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="wbe-no-dates">
                            <?php echo esc_html__('No specific dates selected', Constants::TEXT_DOMAIN); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <div class="wbe-dates-actions">
                    <button type="button" id="wbe-clear-specific" class="button button-small">
                        <?php echo esc_html__('Clear All', Constants::TEXT_DOMAIN); ?>
                    </button>
                    <button type="button" id="wbe-import-specific" class="button button-small">
                        <?php echo esc_html__('Import Dates', Constants::TEXT_DOMAIN); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Exclusions Section -->
    <div class="wbe-form-section">
        <h3 class="wbe-form-section-title">
            <span class="dashicons dashicons-dismiss"></span>
            <?php echo esc_html__('Exclude Dates', Constants::TEXT_DOMAIN); ?>
        </h3>
        
        <div class="wbe-form-description">
            <?php echo esc_html__('Select dates that should be excluded from availability. These override all other availability rules.', Constants::TEXT_DOMAIN); ?>
        </div>
        
        <div class="wbe-exclusions-container">
            <!-- Calendar will be loaded here by JavaScript -->
            <div id="wbe-exclusion-calendar-container" class="wbe-calendar-container">
                <div class="wbe-calendar-loading">
                    <span class="spinner is-active"></span>
                    <?php echo esc_html__('Loading calendar...', Constants::TEXT_DOMAIN); ?>
                </div>
            </div>
            
            <div class="wbe-selected-dates">
                <h4 class="wbe-selected-dates-title">
                    <?php echo esc_html__('Excluded Dates', Constants::TEXT_DOMAIN); ?>
                    <span class="wbe-selected-count" id="wbe-exclusion-count">0</span>
                </h4>
                
                <div id="wbe-exclusions-list" class="wbe-dates-list">
                    <?php if (!empty($selected_dates['exclusions'])): ?>
                        <?php foreach ($selected_dates['exclusions'] as $date): ?>
                            <div class="wbe-date-tag" data-date="<?php echo esc_attr($date); ?>">
                                <span class="wbe-date-text"><?php echo esc_html($date); ?></span>
                                <button type="button" class="wbe-remove-date" data-date="<?php echo esc_attr($date); ?>" data-type="exclusion">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="wbe-no-dates">
                            <?php echo esc_html__('No dates excluded', Constants::TEXT_DOMAIN); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <div class="wbe-dates-actions">
                    <button type="button" id="wbe-clear-exclusions" class="button button-small">
                        <?php echo esc_html__('Clear All', Constants::TEXT_DOMAIN); ?>
                    </button>
                    <button type="button" id="wbe-import-exclusions" class="button button-small">
                        <?php echo esc_html__('Import Dates', Constants::TEXT_DOMAIN); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="wbe-form-actions">
        <div class="wbe-form-validation" id="wbe-form-validation" style="display: none;">
            <div class="wbe-validation-message" id="wbe-validation-message"></div>
        </div>
        
        <div class="wbe-action-buttons">
            <button type="button" id="wbe-preview-changes" class="button button-secondary button-large">
                <span class="dashicons dashicons-visibility"></span>
                <?php echo esc_html__('Preview Changes', Constants::TEXT_DOMAIN); ?>
            </button>
            
            <button type="button" id="wbe-apply-changes" class="button button-primary button-large">
                <span class="dashicons dashicons-update"></span>
                <?php echo esc_html__('Apply Changes', Constants::TEXT_DOMAIN); ?>
            </button>
            
            <button type="button" id="wbe-reset-form" class="button button-link">
                <?php echo esc_html__('Reset Form', Constants::TEXT_DOMAIN); ?>
            </button>
        </div>
        
        <div class="wbe-form-disclaimer">
            <p class="wbe-disclaimer-text">
                <strong><?php echo esc_html__('Important:', Constants::TEXT_DOMAIN); ?></strong>
                <?php echo esc_html__('Empty fields will NOT overwrite existing data. Only filled fields will be updated.', Constants::TEXT_DOMAIN); ?>
            </p>
        </div>
    </div>

    <!-- Hidden fields for form state -->
    <input type="hidden" id="wbe-form-nonce" value="<?php echo wp_create_nonce('wbe_form_nonce'); ?>">
    <input type="hidden" id="wbe-current-changes" value="<?php echo esc_attr(json_encode($changes)); ?>">
    
    <!-- Template for date tag -->
    <template id="wbe-date-tag-template">
        <div class="wbe-date-tag">
            <span class="wbe-date-text"></span>
            <button type="button" class="wbe-remove-date">
                <span class="dashicons dashicons-no"></span>
            </button>
        </div>
    </template>
</div>

<!-- Import Dates Modal -->
<div id="wbe-import-dates-modal" class="wbe-modal" style="display: none;">
    <div class="wbe-modal-content">
        <div class="wbe-modal-header">
            <h2><?php echo esc_html__('Import Dates', Constants::TEXT_DOMAIN); ?></h2>
            <button type="button" class="wbe-modal-close">&times;</button>
        </div>
        <div class="wbe-modal-body">
            <div class="wbe-import-options">
                <div class="wbe-import-option">
                    <h3><?php echo esc_html__('Paste Dates', Constants::TEXT_DOMAIN); ?></h3>
                    <p><?php echo esc_html__('Enter dates separated by commas, spaces, or new lines:', Constants::TEXT_DOMAIN); ?></p>
                    <textarea id="wbe-import-textarea" 
                              class="wbe-import-textarea" 
                              placeholder="DD/MM/YYYY, DD/MM/YYYY, ..."></textarea>
                </div>
                
                <div class="wbe-import-option">
                    <h3><?php echo esc_html__('Date Range', Constants::TEXT_DOMAIN); ?></h3>
                    <p><?php echo esc_html__('Generate dates between two dates:', Constants::TEXT_DOMAIN); ?></p>
                    <div class="wbe-import-range">
                        <input type="text" id="wbe-import-start" class="wbe-date-input" placeholder="Start date">
                        <span class="wbe-import-range-separator">to</span>
                        <input type="text" id="wbe-import-end" class="wbe-date-input" placeholder="End date">
                    </div>
                </div>
                
                <div class="wbe-import-preview">
                    <h4><?php echo esc_html__('Preview', Constants::TEXT_DOMAIN); ?></h4>
                    <div id="wbe-import-preview" class="wbe-dates-list"></div>
                </div>
            </div>
        </div>
        <div class="wbe-modal-footer">
            <button type="button" class="button button-secondary wbe-modal-close">
                <?php echo esc_html__('Cancel', Constants::TEXT_DOMAIN); ?>
            </button>
            <button type="button" id="wbe-confirm-import" class="button button-primary">
                <?php echo esc_html__('Import Dates', Constants::TEXT_DOMAIN); ?>
            </button>
        </div>
    </div>
</div>