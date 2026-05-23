<?php
/**
 * Single Transaction Edit Screen
 *
 * WordPress / WooCommerce style edit page for a single point transaction.
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap twork-points-admin">
    <h1>
        <?php
        printf(
            /* translators: %d: transaction ID */
            esc_html__('Edit Point Transaction #%d', 'twork-points'),
            intval($transaction->id)
        );
        ?>
    </h1>

    <?php if (isset($_GET['updated']) && intval($_GET['updated']) === 1) : ?>
        <div id="message" class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Point transaction updated successfully.', 'twork-points'); ?></p>
        </div>
    <?php endif; ?>

    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=twork-points-transactions')); ?>" class="button">
            <?php esc_html_e('Back to transactions', 'twork-points'); ?>
        </a>
    </p>

    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-2">
            <div id="post-body-content">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('twork_points_update_transaction_status', 'twork_points_txn_nonce'); ?>
                    <input type="hidden" name="action" value="twork_points_update_transaction_status" />
                    <input type="hidden" name="transaction_id" value="<?php echo esc_attr($transaction->id); ?>" />

                    <div class="postbox">
                        <h2 class="hndle">
                            <span><?php esc_html_e('Transaction details', 'twork-points'); ?></span>
                        </h2>
                        <div class="inside">
                            <table class="form-table">
                                <tbody>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('User', 'twork-points'); ?></th>
                                        <td>
                                            <?php if ($user) : ?>
                                                <a href="<?php echo esc_url(add_query_arg(array('page' => 'twork-points-users', 'user_id' => $user->ID), admin_url('admin.php'))); ?>">
                                                    <?php echo esc_html($user->display_name ?: $user->user_email); ?>
                                                </a>
                                                <p class="description">
                                                    <?php echo esc_html(sprintf(__('User ID: %d', 'twork-points'), $user->ID)); ?>
                                                </p>
                                            <?php else : ?>
                                                <?php esc_html_e('Unknown user', 'twork-points'); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php esc_html_e('Order', 'twork-points'); ?></th>
                                        <td>
                                            <?php
                                            $raw_order_id = isset($transaction->order_id) ? $transaction->order_id : '';
                                            $display_order_id = preg_replace('/[^0-9]/', '', (string) $raw_order_id);
                                            ?>
                                            <?php if (! empty($display_order_id)) : ?>
                                                <a href="<?php echo esc_url(admin_url('post.php?post=' . absint($display_order_id) . '&action=edit')); ?>">
                                                    #<?php echo esc_html($display_order_id); ?>
                                                </a>
                                            <?php else : ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php esc_html_e('Type', 'twork-points'); ?></th>
                                        <td>
                                            <strong><?php echo esc_html(ucfirst($transaction->type)); ?></strong>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            <label for="twork_points_points"><?php esc_html_e('Points', 'twork-points'); ?></label>
                                        </th>
                                        <td>
                                            <input type="number" name="points" id="twork_points_points" value="<?php echo esc_attr($transaction->points); ?>" />
                                            <p class="description">
                                                <?php esc_html_e('Adjust the number of points for this transaction.', 'twork-points'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            <label for="twork_points_status"><?php esc_html_e('Status', 'twork-points'); ?></label>
                                        </th>
                                        <td>
                                            <?php $status = $transaction->status ?: 'approved'; ?>
                                            <select name="status" id="twork_points_status">
                                                <option value="pending" <?php selected($status, 'pending'); ?>>
                                                    <?php esc_html_e('Pending', 'twork-points'); ?>
                                                </option>
                                                <option value="approved" <?php selected($status, 'approved'); ?>>
                                                    <?php esc_html_e('Approved', 'twork-points'); ?>
                                                </option>
                                                <option value="rejected" <?php selected($status, 'rejected'); ?>>
                                                    <?php esc_html_e('Rejected', 'twork-points'); ?>
                                                </option>
                                            </select>
                                            <p class="description">
                                                <?php esc_html_e('Only approved transactions are counted towards the user balance.', 'twork-points'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            <label for="twork_points_description"><?php esc_html_e('Description', 'twork-points'); ?></label>
                                        </th>
                                        <td>
                                            <textarea name="description" id="twork_points_description" rows="4" class="large-text"><?php echo esc_textarea($transaction->description); ?></textarea>
                                            <p class="description">
                                                <?php esc_html_e('Internal note explaining why these points were granted/changed.', 'twork-points'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php esc_html_e('Created at', 'twork-points'); ?></th>
                                        <td>
                                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php esc_html_e('Expires at', 'twork-points'); ?></th>
                                        <td>
                                            <?php echo $transaction->expires_at ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->expires_at))) : '&mdash;'; ?>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php esc_html_e('Expired?', 'twork-points'); ?></th>
                                        <td>
                                            <?php echo !empty($transaction->is_expired) ? esc_html__('Yes', 'twork-points') : esc_html__('No', 'twork-points'); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php submit_button(__('Save transaction', 'twork-points')); ?>
                </form>
            </div>

            <div id="postbox-container-1" class="postbox-container">
                <div class="postbox">
                    <h2 class="hndle">
                        <span><?php esc_html_e('Transaction summary', 'twork-points'); ?></span>
                    </h2>
                    <div class="inside">
                        <p>
                            <strong><?php esc_html_e('Current transaction points:', 'twork-points'); ?></strong>
                            <?php echo esc_html(number_format_i18n($transaction->points)); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Type:', 'twork-points'); ?></strong>
                            <?php echo esc_html(ucfirst($transaction->type)); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Status:', 'twork-points'); ?></strong>
                            <?php echo esc_html(ucfirst($transaction->status ?: 'approved')); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Order reference:', 'twork-points'); ?></strong>
                            <?php echo isset($display_order_id) && $display_order_id ? esc_html('#' . $display_order_id) : '&mdash;'; ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Editing this transaction will immediately recalculate the customer\'s point balance.', 'twork-points'); ?>
                        </p>
                    </div>
                </div>

                <div class="postbox">
                    <h2 class="hndle">
                        <span><?php esc_html_e('Customer points overview', 'twork-points'); ?></span>
                    </h2>
                    <div class="inside">
                        <p>
                            <strong><?php esc_html_e('Current balance:', 'twork-points'); ?></strong>
                            <?php echo isset($current_balance) ? esc_html(number_format_i18n($current_balance)) : '&mdash;'; ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Lifetime earned:', 'twork-points'); ?></strong>
                            <?php echo isset($lifetime_earned) ? esc_html(number_format_i18n($lifetime_earned)) : '&mdash;'; ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Lifetime redeemed:', 'twork-points'); ?></strong>
                            <?php echo isset($lifetime_redeemed) ? esc_html(number_format_i18n($lifetime_redeemed)) : '&mdash;'; ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Lifetime expired:', 'twork-points'); ?></strong>
                            <?php echo isset($lifetime_expired) ? esc_html(number_format_i18n($lifetime_expired)) : '&mdash;'; ?>
                        </p>
                        <?php if (!empty($billing_phone)) : ?>
                            <p>
                                <strong><?php esc_html_e('Billing phone:', 'twork-points'); ?></strong>
                                <?php echo esc_html($billing_phone); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


