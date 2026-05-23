<?php
/**
 * Admin Dashboard Template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap twork-points-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e('T-Work Points Dashboard', 'twork-points'); ?></h1>
    <hr class="wp-header-end" />

    <div class="twork-cards-grid">
        <div class="twork-card">
            <h3><?php esc_html_e('Current Balance', 'twork-points'); ?></h3>
            <p class="twork-card-number"><?php echo number_format_i18n($summary['current_balance'] ?? 0); ?></p>
            <span class="twork-card-label"><?php esc_html_e('Total points currently available across all users.', 'twork-points'); ?></span>
        </div>
        <div class="twork-card">
            <h3><?php esc_html_e('Total Earned', 'twork-points'); ?></h3>
            <p class="twork-card-number"><?php echo number_format_i18n($summary['total_earned'] ?? 0); ?></p>
            <span class="twork-card-label"><?php esc_html_e('All-time points awarded', 'twork-points'); ?></span>
        </div>
        <div class="twork-card">
            <h3><?php esc_html_e('Total Redeemed', 'twork-points'); ?></h3>
            <p class="twork-card-number"><?php echo number_format_i18n($summary['total_redeemed'] ?? 0); ?></p>
            <span class="twork-card-label"><?php esc_html_e('All-time points redeemed for rewards', 'twork-points'); ?></span>
        </div>
        <div class="twork-card">
            <h3><?php esc_html_e('Active Users', 'twork-points'); ?></h3>
            <p class="twork-card-number"><?php echo number_format_i18n($summary['active_users'] ?? 0); ?></p>
            <span class="twork-card-label"><?php esc_html_e('Customers who have point activity', 'twork-points'); ?></span>
        </div>
    </div>

    <div class="twork-dashboard-columns">
        <div class="twork-dashboard-column">
            <h2><?php esc_html_e('Recent Transactions', 'twork-points'); ?></h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'twork-points'); ?></th>
                        <th><?php esc_html_e('User', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Type', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Points', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Description', 'twork-points'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($recent_transactions)) : ?>
                        <?php foreach ($recent_transactions as $transaction) :
                            $user = get_user_by('ID', $transaction['user_id']);
                            ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction['created_at']))); ?></td>
                                <td>
                                    <?php
                                    if ($user) {
                                        printf(
                                            '<a href="%s">%s</a>',
                                            esc_url(get_edit_user_link($user->ID)),
                                            esc_html($user->display_name)
                                        );
                                    } else {
                                        esc_html_e('Unknown user', 'twork-points');
                                    }
                                    ?>
                                </td>
                                <td><span class="twork-type-badge twork-type-<?php echo esc_attr($transaction['type']); ?>"><?php echo esc_html(ucfirst($transaction['type'])); ?></span></td>
                                <td><?php echo esc_html(number_format_i18n($transaction['points'])); ?></td>
                                <td><?php echo esc_html($transaction['description']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e('No transactions found.', 'twork-points'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="twork-dashboard-column">
            <h2><?php esc_html_e('Recent Adjustments', 'twork-points'); ?></h2>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'twork-points'); ?></th>
                        <th><?php esc_html_e('User', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Points', 'twork-points'); ?></th>
                        <th><?php esc_html_e('Reason', 'twork-points'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($recent_adjustments)) : ?>
                        <?php foreach ($recent_adjustments as $transaction) :
                            $user = get_user_by('ID', $transaction['user_id']);
                            ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($transaction['created_at']))); ?></td>
                                <td>
                                    <?php
                                    if ($user) {
                                        echo esc_html($user->display_name);
                                    } else {
                                        esc_html_e('Unknown user', 'twork-points');
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html(number_format_i18n($transaction['points'])); ?></td>
                                <td><?php echo esc_html($transaction['description']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No recent adjustments.', 'twork-points'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
