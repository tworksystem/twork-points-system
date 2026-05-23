<?php
/**
 * User profile integration template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<h2><?php esc_html_e('Point Balance', 'twork-points'); ?></h2>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Current balance (calculated)', 'twork-points'); ?></label></th>
        <td>
            <strong><?php echo esc_html(number_format_i18n($balance)); ?></strong>
        </td>
    </tr>
    <tr>
        <th><label for="twork_points_adjust_amount"><?php esc_html_e('Adjust points', 'twork-points'); ?></label></th>
        <td>
            <?php wp_nonce_field('twork_points_profile_update', 'twork_points_profile_nonce'); ?>
            <input type="number" name="twork_points_adjust_amount" id="twork_points_adjust_amount" value="0" />
            <p class="description"><?php esc_html_e('Use positive values to add points, negative values to deduct.', 'twork-points'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="twork_points_adjust_reason"><?php esc_html_e('Reason', 'twork-points'); ?></label></th>
        <td>
            <input type="text" class="regular-text" name="twork_points_adjust_reason" id="twork_points_adjust_reason" placeholder="<?php esc_attr_e('Optional note for audit trail', 'twork-points'); ?>" />
        </td>
    </tr>
</table>
