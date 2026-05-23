<?php
/**
 * Admin Settings Template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap twork-points-admin">
    <h1><?php esc_html_e('Point System Settings', 'twork-points'); ?></h1>

    <div class="twork-inline-help">
        <h2><?php esc_html_e('Need a refresher?', 'twork-points'); ?></h2>
        <ul>
            <li><?php esc_html_e('Customers move through tiers automatically as they earn lifetime points: Bronze (5,000), Silver (10,000), Gold (20,000) and Platinum (50,000). Your app applies the tier multipliers on top of the base earning rate configured below.', 'twork-points'); ?></li>
            <li><?php esc_html_e('Expiration gently removes stale points. Set the value to 0 to keep points active forever, or choose a duration that matches your promotional cadence.', 'twork-points'); ?></li>
            <li><?php esc_html_e('Manual adjustments are recorded alongside the reason you enter, so leave a clear note when adding or removing points on behalf of a customer.', 'twork-points'); ?></li>
        </ul>
    </div>

    <?php $field_errors = isset($field_errors) && is_array($field_errors) ? $field_errors : array(); ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="twork-settings-form">
        <?php wp_nonce_field('twork_points_save_settings'); ?>
        <input type="hidden" name="action" value="twork_points_save_settings" />

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="twork_points_rate">
                            <?php esc_html_e('Points Earning Rate', 'twork-points'); ?>
                            <span class="twork-tooltip" title="<?php esc_attr_e('Base points earned per currency unit before tier multipliers are applied.', 'twork-points'); ?>">
                                <span class="dashicons dashicons-editor-help"></span>
                            </span>
                        </label>
                    </th>
                    <td>
                        <input type="number" step="0.01" min="0" name="twork_points_rate" id="twork_points_rate" value="<?php echo esc_attr($options['points_rate']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Number of points earned for each 1 unit of currency spent.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['points_rate'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['points_rate']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="twork_points_redemption_rate">
                            <?php esc_html_e('Points Redemption Rate', 'twork-points'); ?>
                            <span class="twork-tooltip" title="<?php esc_attr_e('How many points are required to generate a 1 unit discount at checkout.', 'twork-points'); ?>">
                                <span class="dashicons dashicons-editor-help"></span>
                            </span>
                        </label>
                    </th>
                    <td>
                        <input type="number" step="0.01" min="1" name="twork_points_redemption_rate" id="twork_points_redemption_rate" value="<?php echo esc_attr($options['redemption_rate']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Number of points required for 1 unit of currency discount.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['redemption_rate'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['redemption_rate']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="twork_points_signup_bonus"><?php esc_html_e('Signup Bonus Points', 'twork-points'); ?></label></th>
                    <td>
                        <input type="number" min="0" name="twork_points_signup_bonus" id="twork_points_signup_bonus" value="<?php echo esc_attr($options['signup_bonus']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Points awarded to customers when they create an account.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['signup_bonus'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['signup_bonus']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="twork_points_referral_bonus"><?php esc_html_e('Referral Bonus Points', 'twork-points'); ?></label></th>
                    <td>
                        <input type="number" min="0" name="twork_points_referral_bonus" id="twork_points_referral_bonus" value="<?php echo esc_attr($options['referral_bonus']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Points awarded to the referrer when a referred user completes an action.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['referral_bonus'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['referral_bonus']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="twork_points_birthday_bonus"><?php esc_html_e('Birthday Bonus Points', 'twork-points'); ?></label></th>
                    <td>
                        <input type="number" min="0" name="twork_points_birthday_bonus" id="twork_points_birthday_bonus" value="<?php echo esc_attr($options['birthday_bonus']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Points awarded to customers on their birthday.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['birthday_bonus'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['birthday_bonus']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="twork_points_min_redemption">
                            <?php esc_html_e('Minimum Points to Redeem', 'twork-points'); ?>
                            <span class="twork-tooltip" title="<?php esc_attr_e('Customers must hold at least this many points before the redemption button appears.', 'twork-points'); ?>">
                                <span class="dashicons dashicons-editor-help"></span>
                            </span>
                        </label>
                    </th>
                    <td>
                        <input type="number" min="0" name="twork_points_min_redemption" id="twork_points_min_redemption" value="<?php echo esc_attr($options['min_redemption']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Minimum number of points required to redeem a reward.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['min_redemption'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['min_redemption']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="twork_points_max_redemption_percent">
                            <?php esc_html_e('Maximum Points per Order (%)', 'twork-points'); ?>
                            <span class="twork-tooltip" title="<?php esc_attr_e('Percentage cap to balance margins—50 means half of an order can be paid with points.', 'twork-points'); ?>">
                                <span class="dashicons dashicons-editor-help"></span>
                            </span>
                        </label>
                    </th>
                    <td>
                        <input type="number" min="0" max="100" name="twork_points_max_redemption_percent" id="twork_points_max_redemption_percent" value="<?php echo esc_attr($options['max_redemption_percent']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Maximum percentage of an order total that can be paid using points.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['max_redemption_percent'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['max_redemption_percent']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="twork_points_expiration_days">
                            <?php esc_html_e('Points Expiration (days)', 'twork-points'); ?>
                            <span class="twork-tooltip" title="<?php esc_attr_e('Controls the lifespan of earned points. Set to 0 to disable expiration entirely.', 'twork-points'); ?>">
                                <span class="dashicons dashicons-editor-help"></span>
                            </span>
                        </label>
                    </th>
                    <td>
                        <input type="number" min="0" name="twork_points_expiration_days" id="twork_points_expiration_days" value="<?php echo esc_attr($options['expiration_days']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Number of days before earned points expire. Set to 0 to disable expiration.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['expiration_days'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['expiration_days']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="twork_points_webhook_url">
                            <?php esc_html_e('Notification Webhook URL', 'twork-points'); ?>
                            <span class="twork-tooltip" title="<?php esc_attr_e('URL of your webhook server for sending push notifications when points are approved. Leave empty to auto-detect.', 'twork-points'); ?>">
                                <span class="dashicons dashicons-editor-help"></span>
                            </span>
                        </label>
                    </th>
                    <td>
                        <input type="url" name="twork_points_webhook_url" id="twork_points_webhook_url" value="<?php echo esc_attr($options['webhook_url'] ?? ''); ?>" class="large-text" placeholder="<?php esc_attr_e('https://your-webhook-server.com/api/webhook/points-approved', 'twork-points'); ?>" />
                        <p class="description"><?php esc_html_e('Webhook URL for sending push notifications when points transactions are approved. Example: https://your-server.com/api/webhook/points-approved', 'twork-points'); ?></p>
                        <p class="description"><strong><?php esc_html_e('Note:', 'twork-points'); ?></strong> <?php esc_html_e('If left empty, the system will attempt to auto-detect based on your site URL.', 'twork-points'); ?></p>
                        <?php if (! empty($field_errors['webhook_url'])) : ?>
                            <p class="twork-field-error"><?php echo esc_html($field_errors['webhook_url']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Lucky Box is configured per-user under T-Work Points > User Points -->
            </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'twork-points')); ?>
    </form>
</div>
