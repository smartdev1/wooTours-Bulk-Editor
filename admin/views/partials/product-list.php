<?php
/**
 * Wootour Bulk Editor - Product List Partial
 * 
 * Template for displaying products in a list with checkboxes.
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
 * @var array $products Array of ProductModel objects
 * @var int $page Current page
 * @var int $total_pages Total pages
 * @var int $total Total products
 */
?>

<?php if (empty($products)): ?>
    <div class="wbe-empty-state">
        <p><?php echo esc_html__('No products found.', Constants::TEXT_DOMAIN); ?></p>
        <?php if (!empty($search_term)): ?>
            <p><?php echo sprintf(
                esc_html__('No products found for "%s".', Constants::TEXT_DOMAIN),
                esc_html($search_term)
            ); ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <table class="wp-list-table widefat fixed striped wbe-product-table">
        <thead>
            <tr>
                <th class="check-column">
                    <input type="checkbox" id="wbe-bulk-select-all">
                </th>
                <th class="column-primary"><?php echo esc_html__('Product', Constants::TEXT_DOMAIN); ?></th>
                <th><?php echo esc_html__('SKU', Constants::TEXT_DOMAIN); ?></th>
                <th><?php echo esc_html__('Categories', Constants::TEXT_DOMAIN); ?></th>
                <th><?php echo esc_html__('Price', Constants::TEXT_DOMAIN); ?></th>
                <th><?php echo esc_html__('Wootour', Constants::TEXT_DOMAIN); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product): ?>
                <tr class="wbe-product-row" data-product-id="<?php echo esc_attr($product->getId()); ?>">
                    <th scope="row" class="check-column">
                        <input type="checkbox" 
                               class="wbe-product-checkbox" 
                               name="product_ids[]" 
                               value="<?php echo esc_attr($product->getId()); ?>"
                               data-product-name="<?php echo esc_attr($product->getName()); ?>">
                    </th>
                    <td class="column-primary">
                        <div class="wbe-product-info">
                            <?php if ($product->getImageUrl()): ?>
                                <img src="<?php echo esc_url($product->getImageUrl()); ?>" 
                                     alt="<?php echo esc_attr($product->getName()); ?>"
                                     class="wbe-product-thumb">
                            <?php endif; ?>
                            <div class="wbe-product-details">
                                <strong class="wbe-product-name">
                                    <a href="<?php echo esc_url($product->getEditUrl()); ?>" target="_blank">
                                        <?php echo esc_html($product->getName()); ?>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo esc_url($product->getEditUrl()); ?>" target="_blank">
                                            <?php echo esc_html__('Edit', Constants::TEXT_DOMAIN); ?>
                                        </a>
                                    </span>
                                    |
                                    <span class="view">
                                        <a href="<?php echo esc_url($product->getViewUrl()); ?>" target="_blank">
                                            <?php echo esc_html__('View', Constants::TEXT_DOMAIN); ?>
                                        </a>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($product->getSku()): ?>
                            <code><?php echo esc_html($product->getSku()); ?></code>
                        <?php else: ?>
                            <span class="wbe-empty">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        $categories = $product->getCategoryNames();
                        if (!empty($categories)): 
                        ?>
                            <div class="wbe-category-tags">
                                <?php foreach (array_slice($categories, 0, 2) as $category): ?>
                                    <span class="wbe-category-tag"><?php echo esc_html($category); ?></span>
                                <?php endforeach; ?>
                                <?php if (count($categories) > 2): ?>
                                    <span class="wbe-category-more" 
                                          title="<?php echo esc_attr(implode(', ', $categories)); ?>">
                                        +<?php echo count($categories) - 2; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="wbe-empty">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($product->getPrice()): ?>
                            <?php echo wc_price($product->getPrice()); ?>
                        <?php else: ?>
                            <span class="wbe-empty">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($product->hasWootour()): ?>
                            <span class="wbe-status-badge wbe-status-active">
                                <span class="dashicons dashicons-yes"></span>
                                <?php echo esc_html__('Active', Constants::TEXT_DOMAIN); ?>
                            </span>
                        <?php else: ?>
                            <span class="wbe-status-badge wbe-status-inactive">
                                <span class="dashicons dashicons-no"></span>
                                <?php echo esc_html__('Inactive', Constants::TEXT_DOMAIN); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>