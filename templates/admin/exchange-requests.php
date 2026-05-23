<?php
/**
 * Exchange / Claim Requests Admin Template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap twork-points-admin">
    <h1><?php esc_html_e('Point Exchange Requests', 'twork-points'); ?></h1>

    <p class="description">
        <?php esc_html_e('Requests submitted from the mobile app when customers want to exchange or withdraw their points. Approve a request to deduct points from the customer balance, or reject it to leave the balance unchanged.', 'twork-points'); ?>
    </p>

    <form method="get" class="twork-filter-form">
        <input type="hidden" name="page" value="twork-points-exchange-requests" />

        <div class="tablenav top">
            <div class="alignleft actions">
                <label for="twork_status_filter" class="screen-reader-text">
                    <?php esc_html_e('Filter by status', 'twork-points'); ?>
                </label>
                <select name="status" id="twork_status_filter">
                    <option value=""><?php esc_html_e('All statuses', 'twork-points'); ?></option>
                    <option value="pending" <?php selected(isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '', 'pending'); ?>>
                        <?php esc_html_e('Pending', 'twork-points'); ?>
                    </option>
                    <option value="approved" <?php selected(isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '', 'approved'); ?>>
                        <?php esc_html_e('Approved', 'twork-points'); ?>
                    </option>
                    <option value="rejected" <?php selected(isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '', 'rejected'); ?>>
                        <?php esc_html_e('Rejected', 'twork-points'); ?>
                    </option>
                </select>

                <label for="twork_user_filter" class="screen-reader-text">
                    <?php esc_html_e('Filter by user ID', 'twork-points'); ?>
                </label>
                <input type="number" name="user_id" id="twork_user_filter" placeholder="<?php esc_attr_e('User ID', 'twork-points'); ?>"
                       value="<?php echo isset($_GET['user_id']) ? intval($_GET['user_id']) : ''; ?>" />

                <label for="twork_per_page" class="screen-reader-text">
                    <?php esc_html_e('Items per page', 'twork-points'); ?>
                </label>
                <input type="number" min="1" max="200" name="per_page" id="twork_per_page" value="<?php echo isset($_GET['per_page']) ? intval($_GET['per_page']) : 25; ?>" />

                <button type="submit" class="button"><?php esc_html_e('Filter', 'twork-points'); ?></button>
            </div>

            <br class="clear" />
        </div>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th><?php esc_html_e('ID', 'twork-points'); ?></th>
            <th><?php esc_html_e('Date', 'twork-points'); ?></th>
            <th><?php esc_html_e('User', 'twork-points'); ?></th>
            <th><?php esc_html_e('Phone', 'twork-points'); ?></th>
            <th><?php esc_html_e('Requested Points', 'twork-points'); ?></th>
            <th><?php esc_html_e('Status', 'twork-points'); ?></th>
            <th><?php esc_html_e('Note', 'twork-points'); ?></th>
            <th><?php esc_html_e('Actions', 'twork-points'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if (!empty($claims)) : ?>
            <?php foreach ($claims as $claim) : ?>
                <?php
                $user = get_user_by('id', $claim->user_id);
                $user_label = $user
                    ? sprintf(
                        '%s (#%d)',
                        esc_html($user->display_name ?: $user->user_email),
                        intval($user->ID)
                    )
                    : sprintf(__('Unknown user (#%d)', 'twork-points'), intval($claim->user_id));

                $status = $claim->status;
                $status_label = ucfirst($status);
                $status_class = 'twork-status-' . sanitize_html_class($status);
                ?>
                <tr>
                    <td><?php echo intval($claim->id); ?></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($claim->created_at))); ?></td>
                    <td><?php echo $user_label; ?></td>
                    <td><?php echo esc_html($claim->phone); ?></td>
                    <td><?php echo number_format_i18n(intval($claim->points)); ?></td>
                    <td>
                        <span class="twork-status-badge <?php echo esc_attr($status_class); ?>">
                            <?php echo esc_html($status_label); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html(wp_trim_words($claim->note, 10)); ?></td>
                    <td>
                        <?php if ($status === 'pending') : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:4px;">
                                <?php wp_nonce_field('twork_points_handle_claim_request', 'twork_points_claim_nonce'); ?>
                                <input type="hidden" name="action" value="twork_points_handle_claim_request" />
                                <input type="hidden" name="request_id" value="<?php echo intval($claim->id); ?>" />
                                <input type="hidden" name="decision" value="approve" />
                                <button type="submit" class="button button-primary">
                                    <?php esc_html_e('Approve', 'twork-points'); ?>
                                </button>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                <?php wp_nonce_field('twork_points_handle_claim_request', 'twork_points_claim_nonce'); ?>
                                <input type="hidden" name="action" value="twork_points_handle_claim_request" />
                                <input type="hidden" name="request_id" value="<?php echo intval($claim->id); ?>" />
                                <input type="hidden" name="decision" value="reject" />
                                <button type="submit" class="button">
                                    <?php esc_html_e('Reject', 'twork-points'); ?>
                                </button>
                            </form>
                        <?php else : ?>
                            <?php if (!empty($claim->processed_at)) : ?>
                                <span class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: status, 2: date */
                                        esc_html__('%1$s on %2$s', 'twork-points'),
                                        esc_html(ucfirst($status)),
                                        esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($claim->processed_at)))
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr>
                <td colspan="8"><?php esc_html_e('No exchange requests found.', 'twork-points'); ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($pagination_links)) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php echo wp_kses_post($pagination_links); ?>
            </div>
        </div>
    <?php endif; ?>
</div>


