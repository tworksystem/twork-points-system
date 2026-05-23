<?php
/**
 * Transactions Admin Template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}

$types = array(
    '' => __('All types', 'twork-points'),
    'earn' => __('Earn', 'twork-points'),
    'redeem' => __('Redeem', 'twork-points'),
    'adjust' => __('Adjust', 'twork-points'),
    'expire' => __('Expire', 'twork-points'),
    'referral' => __('Referral', 'twork-points'),
    'birthday' => __('Birthday', 'twork-points'),
    'refund' => __('Refund', 'twork-points'),
);
$is_trash_view = isset($args['trashed']) && $args['trashed'] == 1;
$view_all_url = add_query_arg(array('page' => 'twork-points-transactions', 'trashed' => 0), admin_url('admin.php'));
$view_trash_url = add_query_arg(array('page' => 'twork-points-transactions', 'trashed' => 1), admin_url('admin.php'));
?>
<div class="wrap twork-points-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e('Point Transactions', 'twork-points'); ?></h1>
    
    <?php if (!$is_trash_view) : ?>
        <a href="<?php echo esc_url($view_trash_url); ?>" class="page-title-action"><?php esc_html_e('View Trash', 'twork-points'); ?></a>
    <?php else : ?>
        <a href="<?php echo esc_url($view_all_url); ?>" class="page-title-action"><?php esc_html_e('View All', 'twork-points'); ?></a>
    <?php endif; ?>
    
    <hr class="wp-header-end">

    <form method="get" class="twork-filter-form">
        <input type="hidden" name="page" value="twork-points-transactions" />
        <input type="hidden" name="paged" value="1" />
        <?php if ($is_trash_view) : ?>
            <input type="hidden" name="trashed" value="1" />
        <?php endif; ?>

        <select name="type">
            <?php foreach ($types as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($args['type'], $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>

        <input type="number" name="user_id" placeholder="<?php esc_attr_e('User ID', 'twork-points'); ?>" value="<?php echo esc_attr($args['user_id']); ?>" />
        <input type="text" name="order_id" placeholder="<?php esc_attr_e('Order ID', 'twork-points'); ?>" value="<?php echo esc_attr($args['order_id']); ?>" />
        <input type="search" name="search" placeholder="<?php esc_attr_e('Search description or order…', 'twork-points'); ?>" value="<?php echo esc_attr($args['search']); ?>" />

        <?php
        $current_per_page = isset($per_page) ? intval($per_page) : 25;
        ?>
        <select name="per_page">
            <?php foreach (array(25, 50, 100, 200) as $option) : ?>
                <option value="<?php echo esc_attr($option); ?>" <?php selected($current_per_page, $option); ?>><?php echo esc_html(sprintf(_n('%d row per page', '%d rows per page', $option, 'twork-points'), $option)); ?></option>
            <?php endforeach; ?>
        </select>

        <?php submit_button(__('Filter', 'twork-points'), 'secondary', '', false); ?>
        <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=twork-points-transactions')); ?>"><?php esc_html_e('Reset', 'twork-points'); ?></a>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="twork-export-form">
        <?php wp_nonce_field('twork_points_export'); ?>
        <input type="hidden" name="action" value="twork_points_export" />
        <input type="hidden" name="type" value="<?php echo esc_attr($args['type']); ?>" />
        <input type="hidden" name="user_id" value="<?php echo esc_attr($args['user_id']); ?>" />
        <input type="hidden" name="order_id" value="<?php echo esc_attr($args['order_id']); ?>" />
        <input type="hidden" name="search" value="<?php echo esc_attr($args['search']); ?>" />
        <?php submit_button(__('Export CSV (filters applied)', 'twork-points'), 'secondary', 'submit', false); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="twork-bulk-transactions-form">
        <?php wp_nonce_field('twork_points_bulk_transactions'); ?>
        <input type="hidden" name="action" value="twork_points_bulk_transactions" />
        <?php if ($is_trash_view) : ?>
            <input type="hidden" name="trashed" value="1" />
        <?php endif; ?>
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'twork-points'); ?></label>
                <select name="bulk_action" id="bulk-action-selector-top">
                    <option value="-1"><?php esc_html_e('Bulk Actions', 'twork-points'); ?></option>
                    <?php if ($is_trash_view) : ?>
                        <option value="untrash"><?php esc_html_e('Restore', 'twork-points'); ?></option>
                        <option value="delete"><?php esc_html_e('Delete Permanently', 'twork-points'); ?></option>
                    <?php else : ?>
                        <option value="trash"><?php esc_html_e('Move to Trash', 'twork-points'); ?></option>
                    <?php endif; ?>
                </select>
                <input type="submit" id="doaction" class="button action" value="<?php esc_attr_e('Apply', 'twork-points'); ?>" />
            </div>
            <?php if (!empty($pagination_links)) : ?>
                <div class="tablenav-pages">
                    <?php echo wp_kses_post($pagination_links); ?>
                </div>
            <?php endif; ?>
        </div>

    <table class="wp-list-table widefat fixed striped table-view-list">
        <thead>
            <tr>
                <td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all" /></td>
                <th><?php esc_html_e('ID', 'twork-points'); ?></th>
                <th><?php esc_html_e('Date', 'twork-points'); ?></th>
                <th><?php esc_html_e('User', 'twork-points'); ?></th>
                <th><?php esc_html_e('Phone', 'twork-points'); ?></th>
                <th><?php esc_html_e('Type', 'twork-points'); ?></th>
                <th><?php esc_html_e('Points', 'twork-points'); ?></th>
                <th><?php esc_html_e('Order', 'twork-points'); ?></th>
                <th><?php esc_html_e('Status', 'twork-points'); ?></th>
                <th><?php esc_html_e('Expires', 'twork-points'); ?></th>
                <th><?php esc_html_e('Expired?', 'twork-points'); ?></th>
                <th><?php esc_html_e('Description', 'twork-points'); ?></th>
                <th><?php esc_html_e('Actions', 'twork-points'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (! empty($transactions)) : ?>
                <?php foreach ($transactions as $transaction) :
                    $user = get_user_by('ID', $transaction['user_id']);
                    $user_phone = $user ? get_user_meta($user->ID, 'billing_phone', true) : '';
                    $is_trashed = !empty($transaction['deleted_at']);
                    ?>
                    <tr<?php echo $is_trashed ? ' class="twork-trash-row"' : ''; ?>>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="transaction_ids[]" value="<?php echo esc_attr($transaction['id']); ?>" />
                        </th>
                        <td><?php echo esc_html($transaction['id']); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction['created_at']))); ?></td>
                        <td>
                            <?php if ($user) : ?>
                                <a href="<?php echo esc_url(add_query_arg(array('page' => 'twork-points-users', 'user_id' => $user->ID), admin_url('admin.php'))); ?>"><?php echo esc_html($user->display_name); ?></a>
                            <?php else : ?>
                                <?php esc_html_e('Unknown user', 'twork-points'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $user_phone ? esc_html($user_phone) : '&mdash;'; ?>
                        </td>
                        <td><span class="twork-type-badge twork-type-<?php echo esc_attr($transaction['type']); ?>"><?php echo esc_html(ucfirst($transaction['type'])); ?></span></td>
                        <td><?php echo esc_html(number_format_i18n($transaction['points'])); ?></td>
                        <td>
                            <?php
                            $raw_order_id = isset($transaction['order_id']) ? $transaction['order_id'] : '';
                            // Extract numeric WooCommerce order ID (handles values like "WC-234" or "234_reverse")
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
                        <td>
                            <?php
                            $status       = isset($transaction['status']) ? $transaction['status'] : 'approved';
                            $status_label = ucfirst($status);
                            $status_class = 'twork-status-' . esc_attr($status);
                            ?>
                            <span class="twork-status-badge <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo $transaction['expires_at'] ? esc_html(date_i18n(get_option('date_format'), strtotime($transaction['expires_at']))) : '&mdash;'; ?>
                        </td>
                        <td><?php echo $transaction['is_expired'] ? esc_html__('Yes', 'twork-points') : esc_html__('No', 'twork-points'); ?></td>
                        <td><?php echo esc_html($transaction['description']); ?></td>
                        <td>
                            <?php if ($is_trash_view) : ?>
                                <?php
                                $base_restore_url = add_query_arg(array(
                                    'action' => 'twork_points_restore_transaction',
                                    'transaction_id' => absint($transaction['id']),
                                    'redirect_to' => esc_url(add_query_arg(array('page' => 'twork-points-transactions', 'trashed' => 1), admin_url('admin.php'))),
                                ), admin_url('admin-post.php'));
                                
                                $base_delete_url = add_query_arg(array(
                                    'action' => 'twork_points_delete_transaction',
                                    'transaction_id' => absint($transaction['id']),
                                    'redirect_to' => esc_url(add_query_arg(array('page' => 'twork-points-transactions', 'trashed' => 1), admin_url('admin.php'))),
                                ), admin_url('admin-post.php'));
                                
                                $restore_url = wp_nonce_url($base_restore_url, 'twork_points_restore_transaction');
                                $delete_url = wp_nonce_url($base_delete_url, 'twork_points_delete_transaction');
                                ?>
                                <a href="<?php echo esc_url($restore_url); ?>" class="twork-restore-link"><?php esc_html_e('Restore', 'twork-points'); ?></a>
                                <span class="separator">|</span>
                                <a href="<?php echo esc_url($delete_url); ?>" class="twork-delete-link twork-delete-permanently" onclick="return confirm('<?php esc_attr_e('Are you sure you want to permanently delete this transaction? This action cannot be undone.', 'twork-points'); ?>');"><?php esc_html_e('Delete Permanently', 'twork-points'); ?></a>
                            <?php else : ?>
                                <?php
                                $edit_url = add_query_arg(array(
                                    'page'           => 'twork-points-transaction-edit',
                                    'transaction_id' => absint($transaction['id']),
                                ), admin_url('admin.php'));
                                $base_trash_url = add_query_arg(array(
                                    'action' => 'twork_points_trash_transaction',
                                    'transaction_id' => absint($transaction['id']),
                                    'redirect_to' => esc_url(add_query_arg(array('page' => 'twork-points-transactions'), admin_url('admin.php'))),
                                ), admin_url('admin-post.php'));
                                
                                $trash_url = wp_nonce_url($base_trash_url, 'twork_points_trash_transaction');
                                ?>
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'twork-points'); ?></a>
                                <span class="separator">|</span>
                                <a href="<?php echo esc_url($trash_url); ?>" class="twork-trash-link"><?php esc_html_e('Trash', 'twork-points'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr>
                    <td colspan="12"><?php esc_html_e('No transactions match your filters.', 'twork-points'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="tablenav bottom">
        <div class="alignleft actions bulkactions">
            <label for="bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'twork-points'); ?></label>
            <select name="bulk_action2" id="bulk-action-selector-bottom">
                <option value="-1"><?php esc_html_e('Bulk Actions', 'twork-points'); ?></option>
                <?php if ($is_trash_view) : ?>
                    <option value="untrash"><?php esc_html_e('Restore', 'twork-points'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete Permanently', 'twork-points'); ?></option>
                <?php else : ?>
                    <option value="trash"><?php esc_html_e('Move to Trash', 'twork-points'); ?></option>
                <?php endif; ?>
            </select>
            <input type="submit" id="doaction2" class="button action" value="<?php esc_attr_e('Apply', 'twork-points'); ?>" />
        </div>
        <?php if (!empty($pagination_links)) : ?>
            <div class="tablenav-pages">
                <?php echo wp_kses_post($pagination_links); ?>
            </div>
        <?php endif; ?>
    </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Toggle all checkboxes
    $('#cb-select-all').on('change', function() {
        $('input[name="transaction_ids[]"]').prop('checked', this.checked);
    });
    
    // Update "select all" checkbox state
    $('input[name="transaction_ids[]"]').on('change', function() {
        var total = $('input[name="transaction_ids[]"]').length;
        var checked = $('input[name="transaction_ids[]"]:checked').length;
        $('#cb-select-all').prop('checked', total === checked);
    });
});
</script>
