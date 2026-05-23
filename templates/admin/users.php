<?php
/**
 * User Points Management Template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap twork-points-admin">
    <h1><?php esc_html_e('User Points Management', 'twork-points'); ?></h1>
    
    <?php
    // Display admin notices
    if (isset($_GET['twork_points_notice'])) {
        $notice = sanitize_key($_GET['twork_points_notice']);
        $class = 'notice-success';
        $message = '';
        
        switch ($notice) {
            case 'adjustment_success':
                $message = __('Points adjusted successfully.', 'twork-points');
                break;
            case 'adjustment_failed':
                $class = 'notice-error';
                $message = __('Failed to adjust points. Please try again.', 'twork-points');
                break;
            case 'invalid_request':
                $class = 'notice-error';
                $message = __('Invalid request. Please check your input and try again.', 'twork-points');
                break;
            case 'invalid_set_value':
                $class = 'notice-error';
                $message = __('Cannot set balance to a negative value.', 'twork-points');
                break;
            case 'no_change':
                $class = 'notice-info';
                $message = __('No change needed. The balance is already at the requested value.', 'twork-points');
                break;
            case 'custom_fields_saved':
                $message = __('Custom fields saved successfully.', 'twork-points');
                break;
        }
        
        if ($message) {
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
        }
    }
    ?>

    <form method="get" class="twork-user-search-form" style="margin-bottom: 20px;">
        <input type="hidden" name="page" value="twork-points-users" />
        <?php if (isset($selected_user) && $selected_user instanceof WP_User) : ?>
            <input type="hidden" name="user_id" value="<?php echo esc_attr($selected_user->ID); ?>" />
        <?php endif; ?>
        <label class="screen-reader-text" for="twork-user-search"><?php esc_html_e('Search users', 'twork-points'); ?></label>
        <input type="search" id="twork-user-search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Search by name or email…', 'twork-points'); ?>" style="width: 300px; padding: 8px;" />
        <?php submit_button(__('Search Users', 'twork-points'), 'secondary', '', false); ?>
        <?php if (!empty($search_query)) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=twork-points-users')); ?>" class="button">
                <?php esc_html_e('Clear Search', 'twork-points'); ?>
            </a>
        <?php endif; ?>
    </form>

    <div class="twork-user-columns">
        <div class="twork-user-column">
            <h2><?php echo !empty($search_query) ? esc_html__('Matching Users', 'twork-points') : esc_html__('All Users', 'twork-points'); ?></h2>
            
            <?php if (!empty($users)) : ?>
                <table class="widefat fixed striped twork-user-list-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('User', 'twork-points'); ?></th>
                            <th><?php esc_html_e('Email', 'twork-points'); ?></th>
                            <th><?php esc_html_e('Points Balance', 'twork-points'); ?></th>
                            <th><?php esc_html_e('Actions', 'twork-points'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($users as $user) : 
                            // Get user's current balance for display (from cached meta for performance)
                            $user_list_balance = get_user_meta($user->ID, 'points_balance', true);
                            if ($user_list_balance === false || $user_list_balance === '') {
                                $user_list_balance = 0;
                            }
                            $user_list_balance = intval($user_list_balance);
                            
                            // Check if this is the selected user
                            $is_selected = ($selected_user instanceof WP_User && $selected_user->ID == $user->ID);
                            $row_class = $is_selected ? 'twork-selected-user' : '';
                            $user_url = add_query_arg(array(
                                'page' => 'twork-points-users',
                                's' => $search_query,
                                'user_id' => $user->ID,
                                'paged' => $paged,
                            ), admin_url('admin.php'));
                        ?>
                            <tr class="<?php echo esc_attr($row_class); ?>" data-user-id="<?php echo esc_attr($user->ID); ?>" style="cursor: pointer; <?php echo $is_selected ? 'background-color: #f0f6fc;' : ''; ?>">
                                <td>
                                    <strong><?php echo esc_html($user->display_name); ?></strong>
                                    <?php if (!empty($user->first_name) || !empty($user->last_name)) : ?>
                                        <br>
                                        <small style="color: #646970;">
                                            <?php echo esc_html(trim($user->first_name . ' ' . $user->last_name)); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($user->user_email); ?></td>
                                <td>
                                    <strong style="color: #2271b1; font-size: 14px;">
                                        <?php echo esc_html(number_format_i18n($user_list_balance)); ?> 
                                        <span style="font-size: 11px; color: #646970;"><?php esc_html_e('pts', 'twork-points'); ?></span>
                                    </strong>
                                </td>
                                <td>
                                    <a class="button button-small button-primary" href="<?php echo esc_url($user_url); ?>">
                                        <?php esc_html_e('Manage Points', 'twork-points'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php
                // Pagination
                if ($total_users > $per_page) {
                    $total_pages = ceil($total_users / $per_page);
                    $base_url = add_query_arg(array(
                        'page' => 'twork-points-users',
                        's' => $search_query,
                        'user_id' => isset($selected_user) && $selected_user instanceof WP_User ? $selected_user->ID : '',
                    ), admin_url('admin.php'));
                    
                    echo '<div class="tablenav" style="margin-top: 15px;">';
                    echo '<div class="tablenav-pages">';
                    
                    // Previous page
                    if ($paged > 1) {
                        echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $paged - 1, $base_url)) . '">‹</a>';
                    }
                    
                    // Page numbers
                    $start_page = max(1, $paged - 2);
                    $end_page = min($total_pages, $paged + 2);
                    
                    if ($start_page > 1) {
                        echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">1</a>';
                        if ($start_page > 2) {
                            echo '<span class="paging-input">…</span>';
                        }
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $paged) {
                            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $i . '</span>';
                        } else {
                            echo '<a class="page-numbers button" href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '">' . $i . '</a>';
                        }
                    }
                    
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<span class="paging-input">…</span>';
                        }
                        echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">' . $total_pages . '</a>';
                    }
                    
                    // Next page
                    if ($paged < $total_pages) {
                        echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $paged + 1, $base_url)) . '">›</a>';
                    }
                    
                    echo '<span class="displaying-num">' . sprintf(
                        __('Displaying %1$d–%2$d of %3$d users', 'twork-points'),
                        ($paged - 1) * $per_page + 1,
                        min($paged * $per_page, $total_users),
                        $total_users
                    ) . '</span>';
                    
                    echo '</div>';
                    echo '</div>';
                }
                ?>
                
                <script>
                jQuery(document).ready(function($) {
                    // Make entire row clickable
                    $('.twork-user-list-table tbody tr').on('click', function(e) {
                        // Don't trigger if clicking on the button
                        if ($(e.target).closest('.button').length === 0) {
                            var userUrl = $(this).find('.button').attr('href');
                            if (userUrl) {
                                window.location.href = userUrl;
                            }
                        }
                    });
                    
                    // Hover effect
                    $('.twork-user-list-table tbody tr').hover(
                        function() {
                            if (!$(this).hasClass('twork-selected-user')) {
                                $(this).css('background-color', '#f6f7f7');
                            }
                        },
                        function() {
                            if (!$(this).hasClass('twork-selected-user')) {
                                $(this).css('background-color', '');
                            }
                        }
                    );
                });
                </script>
            <?php else : ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('No users found.', 'twork-points'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="twork-user-column">
            <h2><?php esc_html_e('User Details', 'twork-points'); ?></h2>
            <?php if ($selected_user instanceof WP_User) : 
                // Get lifetime stats if available
                $lifetime_earned = get_user_meta($selected_user->ID, 'lifetime_points_earned', true);
                $lifetime_redeemed = get_user_meta($selected_user->ID, 'lifetime_points_redeemed', true);
                if ($lifetime_earned === false || $lifetime_earned === '') {
                    $lifetime_earned = 0;
                }
                if ($lifetime_redeemed === false || $lifetime_redeemed === '') {
                    $lifetime_redeemed = 0;
                }
            ?>
                <div class="twork-user-summary" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;"><?php echo esc_html($selected_user->display_name); ?></h3>
                    <p><strong><?php esc_html_e('Email:', 'twork-points'); ?></strong> <?php echo esc_html($selected_user->user_email); ?></p>
                    <p><strong><?php esc_html_e('User ID:', 'twork-points'); ?></strong> <?php echo esc_html($selected_user->ID); ?></p>
                    
                    <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-top: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <strong style="font-size: 16px;"><?php esc_html_e('Current Balance:', 'twork-points'); ?></strong>
                            <span style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo esc_html(number_format_i18n($user_balance)); ?> <?php esc_html_e('points', 'twork-points'); ?></span>
                        </div>
                        <div style="display: flex; gap: 20px; font-size: 13px; color: #646970;">
                            <span><strong><?php esc_html_e('Lifetime Earned:', 'twork-points'); ?></strong> <?php echo esc_html(number_format_i18n($lifetime_earned)); ?></span>
                            <span><strong><?php esc_html_e('Lifetime Redeemed:', 'twork-points'); ?></strong> <?php echo esc_html(number_format_i18n($lifetime_redeemed)); ?></span>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg(array('page' => 'twork-points-users', 's' => $search_query, 'user_id' => $selected_user->ID, 'recalculate' => '1'), admin_url('admin.php'))); ?>" class="button button-small" style="margin-top: 10px;">
                            <?php esc_html_e('🔄 Refresh Balance', 'twork-points'); ?>
                        </a>
                    </div>
                </div>

                <?php if (current_user_can('manage_users') || current_user_can('manage_woocommerce') || current_user_can('manage_options')) : ?>
                    <div class="twork-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px;">
                        <h3 style="margin-top: 0;"><?php esc_html_e('Manage Points', 'twork-points'); ?></h3>
                        
                        <!-- Quick Actions -->
                        <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                            <strong style="display: block; margin-bottom: 10px;"><?php esc_html_e('Quick Actions:', 'twork-points'); ?></strong>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="button" class="button quick-action-btn" data-points="100"><?php esc_html_e('+100 Points', 'twork-points'); ?></button>
                                <button type="button" class="button quick-action-btn" data-points="500"><?php esc_html_e('+500 Points', 'twork-points'); ?></button>
                                <button type="button" class="button quick-action-btn" data-points="1000"><?php esc_html_e('+1000 Points', 'twork-points'); ?></button>
                                <button type="button" class="button quick-action-btn" data-points="-100"><?php esc_html_e('-100 Points', 'twork-points'); ?></button>
                                <button type="button" class="button quick-action-btn" data-points="-500"><?php esc_html_e('-500 Points', 'twork-points'); ?></button>
                            </div>
                        </div>

                        <!-- Adjustment Form -->
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="twork-adjust-form" id="twork-adjust-form">
                            <?php wp_nonce_field('twork_points_adjust_user_points'); ?>
                            <input type="hidden" name="action" value="twork_points_adjust_user_points" />
                            <input type="hidden" name="user_id" value="<?php echo esc_attr($selected_user->ID); ?>" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr(add_query_arg(array('page' => 'twork-points-users', 's' => $search_query, 'user_id' => $selected_user->ID), admin_url('admin.php'))); ?>" />

                            <table class="form-table">
                                <tr>
                                    <th><label for="twork-adjust-type"><?php esc_html_e('Action Type', 'twork-points'); ?></label></th>
                                    <td>
                                        <select name="adjust_type" id="twork-adjust-type" style="width: 100%;">
                                            <option value="adjust"><?php esc_html_e('Adjust (Add/Subtract)', 'twork-points'); ?></option>
                                            <option value="set"><?php esc_html_e('Set to Specific Value', 'twork-points'); ?></option>
                                        </select>
                                        <p class="description" id="adjust-type-help"><?php esc_html_e('Adjust: Use positive numbers to add, negative to deduct. Set: Enter the exact balance you want.', 'twork-points'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="twork-adjust-points"><?php esc_html_e('Points', 'twork-points'); ?></label></th>
                                    <td>
                                        <input type="number" name="points" id="twork-adjust-points" value="0" step="1" style="width: 200px; font-size: 16px; padding: 8px;" />
                                        <span id="current-balance-display" style="margin-left: 10px; color: #646970; font-size: 13px;"></span>
                                        <p class="description" id="points-help"><?php esc_html_e('Use positive numbers to add points, negative numbers to deduct points.', 'twork-points'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="twork-adjust-reason"><?php esc_html_e('Reason', 'twork-points'); ?> <span style="color: #d63638;">*</span></label></th>
                                    <td>
                                        <input type="text" class="regular-text" name="reason" id="twork-adjust-reason" placeholder="<?php esc_attr_e('Required: Enter reason for this adjustment', 'twork-points'); ?>" required />
                                        <p class="description"><?php esc_html_e('This will be logged in the transaction history for audit purposes.', 'twork-points'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Apply Changes', 'twork-points'); ?></button>
                                <button type="reset" class="button"><?php esc_html_e('Reset', 'twork-points'); ?></button>
                            </p>
                        </form>
                    </div>

                    <script>
                    jQuery(document).ready(function($) {
                        var currentBalance = <?php echo intval($user_balance); ?>;
                        
                        // Quick action buttons
                        $('.quick-action-btn').on('click', function() {
                            var points = parseInt($(this).data('points'));
                            $('#twork-adjust-points').val(points);
                            $('#twork-adjust-type').val('adjust');
                            updateHelpText();
                            $('#twork-adjust-reason').focus();
                        });
                        
                        // Adjust type change
                        $('#twork-adjust-type').on('change', function() {
                            updateHelpText();
                        });
                        
                        // Points input change
                        $('#twork-adjust-points').on('input', function() {
                            updateBalanceDisplay();
                        });
                        
                        function updateHelpText() {
                            var adjustType = $('#twork-adjust-type').val();
                            var points = parseInt($('#twork-adjust-points').val()) || 0;
                            
                            if (adjustType === 'set') {
                                $('#points-help').text('<?php esc_attr_e('Enter the exact point balance you want this user to have.', 'twork-points'); ?>');
                                $('#adjust-type-help').text('<?php esc_attr_e('Set: Enter the exact balance you want. The system will calculate the difference and apply it automatically.', 'twork-points'); ?>');
                            } else {
                                $('#points-help').text('<?php esc_attr_e('Use positive numbers to add points, negative numbers to deduct points.', 'twork-points'); ?>');
                                $('#adjust-type-help').text('<?php esc_attr_e('Adjust: Use positive numbers to add, negative to deduct.', 'twork-points'); ?>');
                            }
                            updateBalanceDisplay();
                        }
                        
                        function updateBalanceDisplay() {
                            var adjustType = $('#twork-adjust-type').val();
                            var points = parseInt($('#twork-adjust-points').val()) || 0;
                            
                            if (adjustType === 'set') {
                                var difference = points - currentBalance;
                                if (points === 0) {
                                    $('#current-balance-display').text('');
                                } else if (difference > 0) {
                                    $('#current-balance-display').html('<strong style="color: #00a32a;">→ Will add ' + Math.abs(difference) + ' points (New balance: ' + points + ')</strong>');
                                } else if (difference < 0) {
                                    $('#current-balance-display').html('<strong style="color: #d63638;">→ Will deduct ' + Math.abs(difference) + ' points (New balance: ' + points + ')</strong>');
                                } else {
                                    $('#current-balance-display').html('<strong style="color: #646970;">→ No change (Already ' + points + ' points)</strong>');
                                }
                            } else {
                                if (points === 0) {
                                    $('#current-balance-display').text('');
                                } else if (points > 0) {
                                    $('#current-balance-display').html('<strong style="color: #00a32a;">→ New balance: ' + (currentBalance + points) + ' points</strong>');
                                } else {
                                    var newBalance = currentBalance + points;
                                    if (newBalance < 0) {
                                        $('#current-balance-display').html('<strong style="color: #d63638;">⚠ Warning: Balance will go negative! (' + newBalance + ' points)</strong>');
                                    } else {
                                        $('#current-balance-display').html('<strong style="color: #d63638;">→ New balance: ' + newBalance + ' points</strong>');
                                    }
                                }
                            }
                        }
                        
                        // Form validation
                        $('#twork-adjust-form').on('submit', function(e) {
                            var adjustType = $('#twork-adjust-type').val();
                            var points = parseInt($('#twork-adjust-points').val()) || 0;
                            
                            if (adjustType === 'set' && points < 0) {
                                e.preventDefault();
                                alert('<?php esc_attr_e('Cannot set balance to a negative value.', 'twork-points'); ?>');
                                return false;
                            }
                            
                            if (points === 0) {
                                e.preventDefault();
                                alert('<?php esc_attr_e('Please enter a non-zero value.', 'twork-points'); ?>');
                                return false;
                            }
                        });
                        
                        // Initialize
                        updateHelpText();
                    });
                    </script>
                <?php else : ?>
                    <div class="notice notice-info">
                        <p><?php esc_html_e('You can view this customer\'s points history but do not have permission to adjust their balance.', 'twork-points'); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Custom Fields Section -->
                <?php if (current_user_can('manage_users') || current_user_can('manage_woocommerce') || current_user_can('manage_options')) : ?>
                    <div class="twork-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; margin-top: 20px;">
                        <h3 style="margin-top: 0;"><?php esc_html_e('Custom Fields', 'twork-points'); ?></h3>
                        <p class="description" style="margin-bottom: 20px;"><?php esc_html_e('Manage custom field values that will be displayed in the app. These values override calculated balances.', 'twork-points'); ?></p>
                        
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="twork-custom-fields-form">
                            <?php wp_nonce_field('twork_points_adjust_user_points'); ?>
                            <input type="hidden" name="action" value="twork_points_adjust_user_points" />
                            <input type="hidden" name="user_id" value="<?php echo esc_attr($selected_user->ID); ?>" />
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr(add_query_arg(array('page' => 'twork-points-users', 's' => $search_query, 'user_id' => $selected_user->ID), admin_url('admin.php'))); ?>" />
                            
                            <table class="form-table">
                                <tr>
                                    <th><label for="twork_custom_points_balance"><?php esc_html_e('Points Balance', 'twork-points'); ?></label></th>
                                    <td>
                                        <?php
                                        $custom_points_balance = get_user_meta($selected_user->ID, 'points_balance', true);
                                        if ($custom_points_balance === false || $custom_points_balance === '') {
                                            $custom_points_balance = $user_balance; // Default to calculated balance
                                        }
                                        ?>
                                        <input type="number" class="regular-text" name="twork_custom_points_balance" id="twork_custom_points_balance" value="<?php echo esc_attr($custom_points_balance); ?>" step="1" style="width: 200px; font-size: 16px; padding: 8px;" />
                                        <p class="description"><?php esc_html_e('Set a custom points balance value. This will be displayed in the app. Leave empty to use calculated balance.', 'twork-points'); ?></p>
                                        <p class="description" style="color: #646970; font-size: 12px;">
                                            <?php esc_html_e('Current calculated balance: ', 'twork-points'); ?>
                                            <strong><?php echo esc_html(number_format_i18n($user_balance)); ?> points</strong>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="twork_custom_my_point"><?php esc_html_e('My Point', 'twork-points'); ?></label></th>
                                    <td>
                                        <?php
                                        $custom_my_point = get_user_meta($selected_user->ID, 'my_point', true);
                                        if ($custom_my_point === false || $custom_my_point === '') {
                                            $custom_my_point = '';
                                        }
                                        ?>
                                        <input type="text" class="regular-text" name="twork_custom_my_point" id="twork_custom_my_point" value="<?php echo esc_attr($custom_my_point); ?>" placeholder="<?php esc_attr_e('Enter point value', 'twork-points'); ?>" style="width: 200px; font-size: 16px; padding: 8px;" />
                                        <p class="description"><?php esc_html_e('Set a custom "My Point" value. This will be displayed in the app under Loyalty Points section.', 'twork-points'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="twork_custom_luckybox_enabled"><?php esc_html_e('Lucky Box', 'twork-points'); ?></label></th>
                                    <td>
                                        <?php $luckybox_enabled = get_user_meta($selected_user->ID, 'twork_luckybox_enabled', true) === '1'; ?>
                                        <input type="hidden" name="twork_custom_luckybox_enabled" value="0" />
                                        <label>
                                            <input type="checkbox" name="twork_custom_luckybox_enabled" id="twork_custom_luckybox_enabled" value="1" <?php checked($luckybox_enabled); ?> />
                                            <?php esc_html_e('Enable Lucky Box button in the app for this user', 'twork-points'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('When enabled, the app shows Lucky Box on Home for this user only.', 'twork-points'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" class="button button-primary button-large" name="save_custom_fields" value="1"><?php esc_html_e('Save Custom Fields', 'twork-points'); ?></button>
                            </p>
                        </form>
                    </div>
                <?php endif; ?>

                <h3><?php esc_html_e('Recent Transactions', 'twork-points'); ?></h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'twork-points'); ?></th>
                            <th><?php esc_html_e('Type', 'twork-points'); ?></th>
                            <th><?php esc_html_e('Points', 'twork-points'); ?></th>
                            <th><?php esc_html_e('Description', 'twork-points'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (! empty($user_transactions)) : ?>
                            <?php foreach ($user_transactions as $transaction) : ?>
                                <tr>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($transaction['created_at']))); ?></td>
                                    <td><span class="twork-type-badge twork-type-<?php echo esc_attr($transaction['type']); ?>"><?php echo esc_html(ucfirst($transaction['type'])); ?></span></td>
                                    <td><?php echo esc_html(number_format_i18n($transaction['points'])); ?></td>
                                    <td><?php echo esc_html($transaction['description']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="4"><?php esc_html_e('No transactions found for this user.', 'twork-points'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e('Select a user from the list to view details and manage their points.', 'twork-points'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
