<?php
/**
 * Reports and Tools Template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap twork-points-admin">
    <h1><?php esc_html_e('Reports & Tools', 'twork-points'); ?></h1>

    <div class="twork-cards-grid">
        <div class="twork-card">
            <h3><?php esc_html_e('Current Balance', 'twork-points'); ?></h3>
            <p class="twork-card-number"><?php echo number_format_i18n($summary['current_balance'] ?? 0); ?></p>
        </div>
        <div class="twork-card">
            <h3><?php esc_html_e('Total Transactions', 'twork-points'); ?></h3>
            <p class="twork-card-number"><?php echo number_format_i18n($summary['total_transactions'] ?? 0); ?></p>
        </div>
        <div class="twork-card">
            <h3><?php esc_html_e('Active Users', 'twork-points'); ?></h3>
            <p class="twork-card-number"><?php echo number_format_i18n($summary['active_users'] ?? 0); ?></p>
        </div>
    </div>

    <div class="twork-reports-grid">
        <div class="twork-reports-column">
            <h2><?php esc_html_e('Top Users by Balance', 'twork-points'); ?></h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('User', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Current Balance', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Earned', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Redeemed', 'twork-points'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($top_users)) : ?>
                        <?php foreach ($top_users as $stats) :
                            $user = get_user_by('ID', $stats['user_id']);
                            $balance = ($stats['earned'] ?? 0) - ($stats['redeemed'] ?? 0) - ($stats['expired'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($user) : ?>
                                        <a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php echo esc_html($user->display_name); ?></a>
                                    <?php else : ?>
                                        <?php esc_html_e('Unknown user', 'twork-points'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(number_format_i18n($balance)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($stats['earned'] ?? 0)); ?></td>
                                <td><?php echo esc_html(number_format_i18n($stats['redeemed'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No user data available.', 'twork-points'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="twork-reports-column">
            <h2><?php esc_html_e('Points Expiring Soon', 'twork-points'); ?></h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('User', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Points', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Expires', 'twork-points'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($expiring_soon)) : ?>
                        <?php foreach ($expiring_soon as $transaction) :
                            $user = get_user_by('ID', $transaction['user_id']);
                            ?>
                            <tr>
                                <td><?php echo $user ? esc_html($user->display_name) : esc_html__('Unknown user', 'twork-points'); ?></td>
                                <td><?php echo esc_html(number_format_i18n($transaction['points'])); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($transaction['expires_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3"><?php esc_html_e('No points expiring soon.', 'twork-points'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Bulk Actions', 'twork-points'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="twork-bulk-actions">
                <?php wp_nonce_field('twork_points_bulk_action'); ?>
                <input type="hidden" name="action" value="twork_points_bulk_action" />

                <select name="bulk_action">
                    <option value=""><?php esc_html_e('Select an action', 'twork-points'); ?></option>
                    <option value="recalculate_balances"><?php esc_html_e('Recalculate all user balances', 'twork-points'); ?></option>
                    <option value="expire_now"><?php esc_html_e('Process expired points now', 'twork-points'); ?></option>
                </select>

                <?php submit_button(__('Run Action', 'twork-points'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('Export Transactions', 'twork-points'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="twork-export-form">
                <?php wp_nonce_field('twork_points_export'); ?>
                <input type="hidden" name="action" value="twork_points_export" />

                <select name="type">
                    <option value=""><?php esc_html_e('All types', 'twork-points'); ?></option>
                    <option value="earn"><?php esc_html_e('Earn', 'twork-points'); ?></option>
                    <option value="redeem"><?php esc_html_e('Redeem', 'twork-points'); ?></option>
                    <option value="adjust"><?php esc_html_e('Adjust', 'twork-points'); ?></option>
                    <option value="expire"><?php esc_html_e('Expire', 'twork-points'); ?></option>
                    <option value="referral"><?php esc_html_e('Referral', 'twork-points'); ?></option>
                    <option value="birthday"><?php esc_html_e('Birthday', 'twork-points'); ?></option>
                    <option value="refund"><?php esc_html_e('Refund', 'twork-points'); ?></option>
                </select>

                <input type="number" name="user_id" placeholder="<?php esc_attr_e('User ID (optional)', 'twork-points'); ?>" />

                <?php submit_button(__('Download CSV', 'twork-points')); ?>
            </form>
        </div>
    </div>
</div>
