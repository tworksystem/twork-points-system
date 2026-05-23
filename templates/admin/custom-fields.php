<?php
/**
 * Admin Custom Fields Template
 *
 * @package TWorkPoints
 */

if (! defined('ABSPATH')) {
    exit;
}

$custom_fields = isset($custom_fields) ? $custom_fields : array();
$message = isset($message) ? $message : '';
?>
<div class="wrap twork-points-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e('Custom Fields Management', 'twork-points'); ?></h1>
    <a href="<?php echo esc_url(add_query_arg(array('page' => 'twork-custom-fields', 'action' => 'add'), admin_url('admin.php'))); ?>" class="page-title-action"><?php esc_html_e('Add New Field', 'twork-points'); ?></a>
    <hr class="wp-header-end" />

    <?php echo $message; ?>

    <div class="twork-inline-help">
        <h2><?php esc_html_e('About Custom Fields', 'twork-points'); ?></h2>
        <p><?php esc_html_e('Custom fields allow you to store and display additional information about users. These fields will appear in user profiles and can be managed here.', 'twork-points'); ?></p>
        <p><strong><?php esc_html_e('Note:', 'twork-points'); ?></strong> <?php esc_html_e('Points Balance is automatically included and cannot be deleted. Points are managed by the system.', 'twork-points'); ?></p>
    </div>

    <?php if (empty($custom_fields)) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e('No custom fields defined. Add your first custom field to get started.', 'twork-points'); ?></p>
        </div>
    <?php else : ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 30%;"><?php esc_html_e('Field Key', 'twork-points'); ?></th>
                    <th style="width: 25%;"><?php esc_html_e('Label', 'twork-points'); ?></th>
                    <th style="width: 15%;"><?php esc_html_e('Type', 'twork-points'); ?></th>
                    <th style="width: 10%;"><?php esc_html_e('Visible', 'twork-points'); ?></th>
                    <th style="width: 10%;"><?php esc_html_e('Editable', 'twork-points'); ?></th>
                    <th style="width: 10%;"><?php esc_html_e('Actions', 'twork-points'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($custom_fields as $index => $field) : 
                    $key = isset($field['key']) ? $field['key'] : '';
                    $is_points = ($key === 'points_balance');
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($key); ?></strong></td>
                        <td><?php echo esc_html(isset($field['label']) ? $field['label'] : $key); ?></td>
                        <td><?php echo esc_html(isset($field['type']) ? ucfirst($field['type']) : 'text'); ?></td>
                        <td>
                            <?php if (isset($field['visible']) && $field['visible']) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-dismiss" style="color: red;"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (isset($field['editable']) && $field['editable']) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-lock" style="color: #999;"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$is_points) : ?>
                                <a href="<?php echo esc_url(add_query_arg(array('page' => 'twork-custom-fields', 'action' => 'edit', 'field' => $index), admin_url('admin.php'))); ?>" class="button button-small"><?php esc_html_e('Edit', 'twork-points'); ?></a>
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('page' => 'twork-custom-fields', 'action' => 'delete', 'field' => $index), admin_url('admin.php')), 'delete_custom_field_' . $index)); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this field?', 'twork-points'); ?>');"><?php esc_html_e('Delete', 'twork-points'); ?></a>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e('System Field', 'twork-points'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (isset($field['description']) && !empty($field['description'])) : ?>
                        <tr>
                            <td colspan="6" style="padding-left: 30px; color: #666; font-style: italic;">
                                <?php echo esc_html($field['description']); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
    // Handle add/edit form
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
    if ($action === 'add' || $action === 'edit') :
        $field_index = isset($_GET['field']) ? intval($_GET['field']) : null;
        $editing_field = null;
        if ($action === 'edit' && $field_index !== null && isset($custom_fields[$field_index])) {
            $editing_field = $custom_fields[$field_index];
        }
    ?>
        <div class="twork-card" style="margin-top: 20px;">
            <h2><?php echo $action === 'add' ? esc_html__('Add New Custom Field', 'twork-points') : esc_html__('Edit Custom Field', 'twork-points'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('twork_custom_fields_save', 'twork_custom_fields_nonce'); ?>
                <input type="hidden" name="action" value="twork_custom_fields_save" />
                <?php if ($editing_field) : ?>
                    <input type="hidden" name="field_index" value="<?php echo esc_attr($field_index); ?>" />
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="field_key"><?php esc_html_e('Field Key', 'twork-points'); ?> <span class="description">(<?php esc_html_e('required', 'twork-points'); ?>)</span></label>
                        </th>
                        <td>
                            <?php if ($editing_field && isset($editing_field['key']) && $editing_field['key'] === 'points_balance') : ?>
                                <input type="text" id="field_key" name="field_key" value="<?php echo esc_attr($editing_field['key']); ?>" readonly class="regular-text" />
                                <p class="description"><?php esc_html_e('Points Balance is a system field and cannot be modified.', 'twork-points'); ?></p>
                            <?php else : ?>
                                <input type="text" id="field_key" name="field_key" value="<?php echo $editing_field ? esc_attr($editing_field['key']) : ''; ?>" class="regular-text" required pattern="[a-z0-9_]+" />
                                <p class="description"><?php esc_html_e('Lowercase letters, numbers, and underscores only. Cannot be changed after creation.', 'twork-points'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="field_label"><?php esc_html_e('Label', 'twork-points'); ?> <span class="description">(<?php esc_html_e('required', 'twork-points'); ?>)</span></label>
                        </th>
                        <td>
                            <input type="text" id="field_label" name="field_label" value="<?php echo $editing_field ? esc_attr($editing_field['label']) : ''; ?>" class="regular-text" required />
                            <p class="description"><?php esc_html_e('Display name for this field.', 'twork-points'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="field_type"><?php esc_html_e('Type', 'twork-points'); ?></label>
                        </th>
                        <td>
                            <select id="field_type" name="field_type" class="regular-text">
                                <option value="text" <?php selected($editing_field && isset($editing_field['type']) ? $editing_field['type'] : 'text', 'text'); ?>><?php esc_html_e('Text', 'twork-points'); ?></option>
                                <option value="number" <?php selected($editing_field && isset($editing_field['type']) ? $editing_field['type'] : '', 'number'); ?>><?php esc_html_e('Number', 'twork-points'); ?></option>
                                <option value="email" <?php selected($editing_field && isset($editing_field['type']) ? $editing_field['type'] : '', 'email'); ?>><?php esc_html_e('Email', 'twork-points'); ?></option>
                                <option value="textarea" <?php selected($editing_field && isset($editing_field['type']) ? $editing_field['type'] : '', 'textarea'); ?>><?php esc_html_e('Textarea', 'twork-points'); ?></option>
                                <option value="date" <?php selected($editing_field && isset($editing_field['type']) ? $editing_field['type'] : '', 'date'); ?>><?php esc_html_e('Date', 'twork-points'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="field_description"><?php esc_html_e('Description', 'twork-points'); ?></label>
                        </th>
                        <td>
                            <textarea id="field_description" name="field_description" rows="3" class="large-text"><?php echo $editing_field ? esc_textarea($editing_field['description']) : ''; ?></textarea>
                            <p class="description"><?php esc_html_e('Optional description for this field.', 'twork-points'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Options', 'twork-points'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="field_visible" value="1" <?php checked($editing_field ? (isset($editing_field['visible']) ? $editing_field['visible'] : true) : true); ?> />
                                    <?php esc_html_e('Visible in user profile', 'twork-points'); ?>
                                </label>
                                <br />
                                <label>
                                    <input type="checkbox" name="field_editable" value="1" <?php checked($editing_field ? (isset($editing_field['editable']) ? $editing_field['editable'] : true) : true); ?> />
                                    <?php esc_html_e('Editable by user', 'twork-points'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="<?php echo $action === 'add' ? esc_attr__('Add Field', 'twork-points') : esc_attr__('Update Field', 'twork-points'); ?>" />
                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'twork-custom-fields'), admin_url('admin.php'))); ?>" class="button"><?php esc_html_e('Cancel', 'twork-points'); ?></a>
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>

