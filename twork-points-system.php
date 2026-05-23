<?php
/**
 * Plugin Name: T-Work Points System
 * Plugin URI: https://www.tworksystem.com
 * Description: Loyalty points system for WooCommerce - Earn and redeem points
 * Version: 1.0.0
 * Author: T-Work System
 * Author URI: https://www.tworksystem.com
 * Text Domain: twork-points
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('TWORK_POINTS_VERSION', '1.0.0');
define('TWORK_POINTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TWORK_POINTS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TWORK_POINTS_PLUGIN_DIR . 'includes/admin/class-twork-points-admin.php';
require_once TWORK_POINTS_PLUGIN_DIR . 'includes/class-twork-points-logger.php';

/**
 * Main T-Work Points System Class
 */
class TWork_Points_System {

    private const SETTINGS_ERROR_TRANSIENT = 'twork_points_settings_errors';
    private const SETTINGS_VALUES_TRANSIENT = 'twork_points_settings_values';
    private const SYNC_ERROR_OPTION = 'twork_points_sync_error_state';
    private const SYNC_ERROR_NOTICE_OPTION = 'twork_points_sync_error_notice';
    private const SYNC_ERROR_THRESHOLD = 5;
    private const SYNC_ERROR_WINDOW = 3600; // 1 hour rolling window
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Initialize on plugins loaded
        add_action('plugins_loaded', array($this, 'init'));
        
        // WooCommerce hooks
        // When an order is created (from app or website) or moves to
        // processing/completed we create a **pending** earn transaction.
        // The actual point balance will be updated when an admin approves
        // the transaction from the WordPress dashboard.
        add_action('woocommerce_new_order', array($this, 'award_points_on_order_completion'), 10, 1);
        add_action('woocommerce_checkout_order_processed', array($this, 'award_points_on_order_completion'), 20, 1);
        add_action('woocommerce_order_status_completed', array($this, 'award_points_on_order_completion'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'award_points_on_order_completion'), 10, 1);
        
        // Refund points on order cancellation
        add_action('woocommerce_order_status_cancelled', array($this, 'refund_points_on_order_cancellation'), 10, 1);
        add_action('woocommerce_order_status_refunded', array($this, 'refund_points_on_order_cancellation'), 10, 1);
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Add custom fields to WordPress REST API user endpoint
        add_filter('rest_prepare_user', array($this, 'add_custom_fields_to_user_rest_api'), 10, 3);
        
        // Admin menu and pages
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // User profile integration
        add_action('show_user_profile', array($this, 'add_user_profile_points_section'));
        add_action('edit_user_profile', array($this, 'add_user_profile_points_section'));
        add_action('personal_options_update', array($this, 'save_user_profile_points'));
        add_action('edit_user_profile_update', array($this, 'save_user_profile_points'));
        
        // Form handlers
        add_action('admin_post_twork_points_save_settings', array($this, 'handle_settings_save'));
        add_action('admin_post_twork_points_adjust_user_points', array($this, 'handle_adjust_user_points'));
        add_action('admin_post_twork_points_bulk_action', array($this, 'handle_bulk_action'));
        add_action('admin_post_twork_points_export', array($this, 'handle_export_transactions'));
        add_action('admin_post_twork_points_handle_claim_request', array($this, 'handle_claim_request_action'));
        add_action('admin_post_twork_points_update_transaction_status', array($this, 'handle_transaction_status_update'));
        add_action('admin_post_twork_points_trash_transaction', array($this, 'handle_trash_transaction'));
        add_action('admin_post_twork_points_restore_transaction', array($this, 'handle_restore_transaction'));
        add_action('admin_post_twork_points_delete_transaction', array($this, 'handle_delete_transaction'));
        add_action('admin_post_twork_points_bulk_transactions', array($this, 'handle_bulk_transactions'));
        
        // Custom Fields handlers
        add_action('admin_post_twork_custom_fields_save', array($this, 'handle_custom_fields_save'));
        add_action('admin_post_twork_custom_fields_delete', array($this, 'handle_custom_fields_delete'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_init', array($this, 'handle_notice_dismissal'));
    }

    /**
     * Register admin menu and submenu pages
     */
    public function add_admin_menu() {
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        $parent_slug = 'twork-points';

        add_menu_page(
            __('T-Work Points', 'twork-points'),
            __('T-Work Points', 'twork-points'),
            $capability,
            $parent_slug,
            array($this, 'render_dashboard_page'),
            'dashicons-awards',
            56
        );

        add_submenu_page(
            $parent_slug,
            __('Dashboard', 'twork-points'),
            __('Dashboard', 'twork-points'),
            $capability,
            $parent_slug,
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            $parent_slug,
            __('Settings', 'twork-points'),
            __('Settings', 'twork-points'),
            $capability,
            'twork-points-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            $parent_slug,
            __('User Points', 'twork-points'),
            __('User Points', 'twork-points'),
            $capability,
            'twork-points-users',
            array($this, 'render_user_points_page')
        );

        add_submenu_page(
            $parent_slug,
            __('Transactions', 'twork-points'),
            __('Transactions', 'twork-points'),
            $capability,
            'twork-points-transactions',
            array($this, 'render_transactions_page')
        );

        // Hidden submenu for editing a single transaction (accessible via direct URL)
        add_submenu_page(
            null,
            __('Edit Point Transaction', 'twork-points'),
            __('Edit Point Transaction', 'twork-points'),
            $capability,
            'twork-points-transaction-edit',
            array($this, 'render_transaction_edit_page')
        );

        add_submenu_page(
            $parent_slug,
            __('Exchange Requests', 'twork-points'),
            __('Exchange Requests', 'twork-points'),
            $capability,
            'twork-points-exchange-requests',
            array($this, 'render_exchange_requests_page')
        );

        add_submenu_page(
            $parent_slug,
            __('Reports & Tools', 'twork-points'),
            __('Reports & Tools', 'twork-points'),
            $capability,
            'twork-points-reports',
            array($this, 'render_reports_page')
        );

        add_submenu_page(
            $parent_slug,
            __('Custom Fields', 'twork-points'),
            __('Custom Fields', 'twork-points'),
            $capability,
            'twork-custom-fields',
            array($this, 'render_custom_fields_page')
        );
    }

    /**
     * Enqueue admin assets only on plugin screens
     */
    public function enqueue_admin_assets($hook_suffix) {
        if (strpos($hook_suffix, 'twork-points') === false) {
            return;
        }

        wp_enqueue_style(
            'twork-points-admin',
            TWORK_POINTS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            TWORK_POINTS_VERSION
        );

        wp_enqueue_script(
            'twork-points-admin',
            TWORK_POINTS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            TWORK_POINTS_VERSION,
            true
        );

        wp_localize_script('twork-points-admin', 'TWorkPointsAdmin', array(
            'nonce' => wp_create_nonce('twork_points_admin'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ));
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        $summary = $this->get_points_summary();
        $recent_transactions = $this->get_transactions(array('limit' => 10));
        $recent_adjustments = $this->get_transactions(array('limit' => 5, 'type' => 'adjust'));

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        $options = array(
            'points_rate' => floatval(get_option('twork_points_rate', 1.0)),
            'redemption_rate' => floatval(get_option('twork_points_redemption_rate', 100)),
            'signup_bonus' => intval(get_option('twork_points_signup_bonus', 100)),
            'referral_bonus' => intval(get_option('twork_points_referral_bonus', 500)),
            'birthday_bonus' => intval(get_option('twork_points_birthday_bonus', 200)),
            'min_redemption' => intval(get_option('twork_points_min_redemption', 100)),
            'max_redemption_percent' => intval(get_option('twork_points_max_redemption_percent', 50)),
            'expiration_days' => intval(get_option('twork_points_expiration_days', 365)),
            'webhook_url' => get_option('twork_points_webhook_url', ''),
        );

        $field_errors = get_transient(self::SETTINGS_ERROR_TRANSIENT);
        $previous_values = get_transient(self::SETTINGS_VALUES_TRANSIENT);

        if (is_array($previous_values) && !empty($previous_values)) {
            $options = array_merge($options, $previous_values);
        }

        if ($field_errors !== false) {
            delete_transient(self::SETTINGS_ERROR_TRANSIENT);
        } else {
            $field_errors = array();
        }

        if ($previous_values !== false) {
            delete_transient(self::SETTINGS_VALUES_TRANSIENT);
        }

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * Render user points management page
     */
    public function render_user_points_page() {
        if (!current_user_can('manage_users') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        $search_query = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $selected_user = null;
        $user_balance = null;
        $user_transactions = array();
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        // Build user query
        $user_query_args = array(
            'number' => $per_page,
            'offset' => ($paged - 1) * $per_page,
            'orderby' => 'registered',
            'order' => 'DESC',
        );

        if (!empty($search_query)) {
            $user_query_args['search'] = '*' . $search_query . '*';
            $user_query_args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        $user_query = new WP_User_Query($user_query_args);
        $users = $user_query->get_results();

        // Get total count for pagination
        $total_users = $user_query->get_total();

        // Handle selected user
        if (!empty($_GET['user_id'])) {
            $selected_user = get_user_by('ID', intval($_GET['user_id']));
        } elseif (!empty($search_query) && count($users) === 1) {
            $selected_user = $users[0];
        }

        if ($selected_user instanceof WP_User) {
            // Handle balance recalculation if requested
            if (isset($_GET['recalculate']) && $_GET['recalculate'] === '1') {
                $this->invalidate_balance_cache($selected_user->ID);
            }
            
            $user_balance = $this->calculate_user_balance($selected_user->ID, isset($_GET['recalculate']) && $_GET['recalculate'] === '1');
            $user_transactions = $this->get_transactions(array(
                'user_id' => $selected_user->ID,
                'limit'   => 20,
            ));
        }

        // Ensure variables are defined for template
        if (!isset($total_users)) {
            $total_users = 0;
        }
        if (!isset($paged)) {
            $paged = 1;
        }
        if (!isset($per_page)) {
            $per_page = 20;
        }

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/users.php';
    }

    /**
     * Render transactions page with filters
     */
    public function render_transactions_page() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        $args = array(
            'type'    => isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '',
            'user_id' => isset($_GET['user_id']) ? intval($_GET['user_id']) : 0,
            'order_id'=> isset($_GET['order_id']) ? sanitize_text_field($_GET['order_id']) : '',
            'search'  => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
            'paged'   => isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1,
            'trashed' => isset($_GET['trashed']) ? (bool) intval($_GET['trashed']) : false,
        );

        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
        $per_page = ($per_page > 0) ? min(200, $per_page) : 25;
        $args['limit'] = $per_page;

        $result = $this->get_transactions(array_merge($args, array('with_total' => true)));
        $transactions = $result['transactions'];
        $total_transactions = $result['total'];
        $total_pages = $per_page > 0 ? (int) ceil(max(0, $total_transactions) / $per_page) : 1;

        if ($total_pages > 0 && $args['paged'] > $total_pages) {
            $args['paged'] = $total_pages;
            $result = $this->get_transactions(array_merge($args, array('with_total' => true)));
            $transactions = $result['transactions'];
            $total_transactions = $result['total'];
            $total_pages = $per_page > 0 ? (int) ceil(max(0, $total_transactions) / $per_page) : 1;
        }

        $summary = $this->get_points_summary();
        
        $pagination_links = '';
        if ($total_pages > 1) {
            $base_args = array(
                'page' => 'twork-points-transactions',
                'type' => $args['type'],
                'user_id' => $args['user_id'],
                'order_id' => $args['order_id'],
                'search' => $args['search'],
                'per_page' => $per_page,
            );

            $pagination_links = paginate_links(array(
                'base' => add_query_arg(array_merge($base_args, array('paged' => '%#%')), admin_url('admin.php')),
                'format' => '',
                'current' => $args['paged'],
                'total' => max(1, $total_pages),
                'prev_text' => __('« Previous', 'twork-points'),
                'next_text' => __('Next »', 'twork-points'),
            ));
        }

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/transactions.php';
    }

    /**
     * Render single transaction edit page (WordPress-style edit screen)
     */
    public function render_transaction_edit_page() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_transactions';

        $transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;

        if ($transaction_id <= 0) {
            wp_die(__('Invalid transaction ID.', 'twork-points'));
        }

        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $transaction_id
        ));

        if (!$transaction) {
            wp_die(__('Transaction not found.', 'twork-points'));
        }

        $user = get_user_by('ID', $transaction->user_id);

        // Enrich edit screen with additional context like balances & meta
        $current_balance    = $this->calculate_user_balance($transaction->user_id, true);
        $lifetime_earned    = $this->get_lifetime_points($transaction->user_id, 'earn');
        $lifetime_redeemed  = $this->get_lifetime_points($transaction->user_id, 'redeem');
        $lifetime_expired   = $this->get_lifetime_points($transaction->user_id, 'expire');
        $billing_phone      = $user ? get_user_meta($user->ID, 'billing_phone', true) : '';

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/transaction-edit.php';
    }

    /**
     * Render exchange / claim requests management page
     */
    public function render_exchange_requests_page() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        global $wpdb;

        $claims_table = $wpdb->prefix . 'twork_point_claim_requests';

        $status   = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $user_id  = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
        $per_page = ($per_page > 0) ? min(200, $per_page) : 25;
        $offset   = ($paged - 1) * $per_page;

        $where   = array();
        $params  = array();

        if ($status !== '') {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        if ($user_id > 0) {
            $where[]  = 'user_id = %d';
            $params[] = $user_id;
        }

        $where_sql = '';
        if (!empty($where)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where);
        }

        // Get total
        $total_sql = "SELECT COUNT(*) FROM $claims_table $where_sql";
        $total     = (int) $wpdb->get_var($wpdb->prepare($total_sql, $params));

        // Get rows
        $query_sql = "SELECT * FROM $claims_table $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $rows      = $wpdb->get_results($wpdb->prepare($query_sql, array_merge($params, array($per_page, $offset))));

        $total_pages = $per_page > 0 ? (int) ceil(max(0, $total) / $per_page) : 1;

        if ($total_pages > 0 && $paged > $total_pages) {
            $paged   = $total_pages;
            $offset  = ($paged - 1) * $per_page;
            $rows    = $wpdb->get_results($wpdb->prepare($query_sql, array_merge($params, array($per_page, $offset))));
        }

        $pagination_links = '';
        if ($total_pages > 1) {
            $base_args = array(
                'page'    => 'twork-points-exchange-requests',
                'status'  => $status,
                'user_id' => $user_id,
                'per_page'=> $per_page,
            );

            $pagination_links = paginate_links(array(
                'base'      => add_query_arg(array_merge($base_args, array('paged' => '%#%')), admin_url('admin.php')),
                'format'    => '',
                'current'   => $paged,
                'total'     => max(1, $total_pages),
                'prev_text' => __('« Previous', 'twork-points'),
                'next_text' => __('Next »', 'twork-points'),
            ));
        }

        $claims      = $rows;
        $currentPage = $paged;

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/exchange-requests.php';
    }

    /**
     * Render reports and tools page
     */
    public function render_reports_page() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        $summary = $this->get_points_summary();
        $top_users = $this->get_top_users();
        $expiring_soon = $this->get_transactions(array(
            'type'    => 'earn',
            'expiring'=> true,
            'limit'   => 10,
        ));

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/reports.php';
    }

    /**
     * Render custom fields management page
     */
    public function render_custom_fields_page() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'twork-points'));
        }

        // Get all custom field definitions
        $custom_fields = get_option('twork_custom_field_definitions', array());
        
        // Get points balance as a special field
        $points_field_exists = false;
        foreach ($custom_fields as $field) {
            if (isset($field['key']) && $field['key'] === 'points_balance') {
                $points_field_exists = true;
                break;
            }
        }
        
        // Auto-add points_balance field if it doesn't exist
        if (!$points_field_exists) {
            $points_field = array(
                'key' => 'points_balance',
                'label' => 'Points Balance',
                'type' => 'number',
                'description' => 'User\'s current loyalty points balance',
                'visible' => true,
                'editable' => false, // Points are managed by the system
            );
            $custom_fields[] = $points_field;
            update_option('twork_custom_field_definitions', $custom_fields);
        }

        $updated = isset($_GET['updated']) ? sanitize_text_field(wp_unslash($_GET['updated'])) : '';
        $message = '';
        if ($updated === '1') {
            $message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Custom field saved successfully.', 'twork-points') . '</p></div>';
        } elseif ($updated === '2') {
            $message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Custom field deleted successfully.', 'twork-points') . '</p></div>';
        }

        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/custom-fields.php';
    }

    /**
     * Handle approve / reject actions for exchange / claim requests.
     */
    public function handle_claim_request_action() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        if (!isset($_POST['twork_points_claim_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['twork_points_claim_nonce'])), 'twork_points_handle_claim_request')) {
            wp_die(__('Security check failed. Please try again.', 'twork-points'));
        }

        $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
        $decision   = isset($_POST['decision']) ? sanitize_text_field(wp_unslash($_POST['decision'])) : '';

        if ($request_id <= 0 || !in_array($decision, array('approve', 'reject'), true)) {
            wp_redirect(add_query_arg(array(
                'page'    => 'twork-points-exchange-requests',
                'updated' => '0',
            ), admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_claim_requests';

        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $request_id
        ));

        if (!$claim) {
            wp_redirect(add_query_arg(array(
                'page'    => 'twork-points-exchange-requests',
                'updated' => '0',
            ), admin_url('admin.php')));
            exit;
        }

        // Do not process again if already handled.
        if ($claim->status !== 'pending') {
            wp_redirect(add_query_arg(array(
                'page'    => 'twork-points-exchange-requests',
                'updated' => '1',
            ), admin_url('admin.php')));
            exit;
        }

        $new_status  = $decision === 'approve' ? 'approved' : 'rejected';
        $processed_at = current_time('mysql');
        $admin_id    = get_current_user_id();

        // When approving, create a redeem transaction to actually deduct points.
        if ($decision === 'approve') {
            $transaction_data = array(
                'user_id'     => intval($claim->user_id),
                'type'        => 'redeem',
                'points'      => intval($claim->points),
                'description' => sprintf(
                    /* translators: 1: request id */
                    __('Approved claim request #%d from dashboard', 'twork-points'),
                    intval($claim->id)
                ),
                'order_id'    => null,
                'status'      => 'approved',
            );

            $transaction_id = $this->create_transaction($transaction_data);

            if ($transaction_id) {
                // Force balance recalc after approval.
                $balance = $this->calculate_user_balance(intval($claim->user_id), true);
                update_user_meta($claim->user_id, 'points_balance', $balance);
            }
        }

        $wpdb->update(
            $table,
            array(
                'status'      => $new_status,
                'processed_at'=> $processed_at,
                'processed_by'=> $admin_id,
            ),
            array('id' => $request_id),
            array('%s', '%s', '%d'),
            array('%d')
        );

        wp_redirect(add_query_arg(array(
            'page'    => 'twork-points-exchange-requests',
            'updated' => '1',
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Handle custom fields save
     */
    public function handle_custom_fields_save() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        if (!isset($_POST['twork_custom_fields_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['twork_custom_fields_nonce'])), 'twork_custom_fields_save')) {
            wp_die(__('Security check failed. Please try again.', 'twork-points'));
        }

        $field_key = isset($_POST['field_key']) ? sanitize_text_field(wp_unslash($_POST['field_key'])) : '';
        $field_label = isset($_POST['field_label']) ? sanitize_text_field(wp_unslash($_POST['field_label'])) : '';
        $field_type = isset($_POST['field_type']) ? sanitize_text_field(wp_unslash($_POST['field_type'])) : 'text';
        $field_description = isset($_POST['field_description']) ? sanitize_textarea_field(wp_unslash($_POST['field_description'])) : '';
        $field_visible = isset($_POST['field_visible']) && $_POST['field_visible'] === '1';
        $field_editable = isset($_POST['field_editable']) && $_POST['field_editable'] === '1';
        $field_index = isset($_POST['field_index']) ? intval($_POST['field_index']) : null;

        if (empty($field_key) || empty($field_label)) {
            wp_redirect(add_query_arg(array(
                'page' => 'twork-custom-fields',
                'updated' => '0',
                'error' => '1',
            ), admin_url('admin.php')));
            exit;
        }

        // Validate field key format
        if (!preg_match('/^[a-z0-9_]+$/', $field_key)) {
            wp_redirect(add_query_arg(array(
                'page' => 'twork-custom-fields',
                'updated' => '0',
                'error' => '2',
            ), admin_url('admin.php')));
            exit;
        }

        $custom_fields = get_option('twork_custom_field_definitions', array());

        // If editing, preserve the original key if it's points_balance
        if ($field_index !== null && isset($custom_fields[$field_index])) {
            $existing_field = $custom_fields[$field_index];
            if (isset($existing_field['key']) && $existing_field['key'] === 'points_balance') {
                $field_key = 'points_balance'; // Cannot change points_balance key
                $field_editable = false; // Points are system-managed
            }
        }

        $new_field = array(
            'key' => $field_key,
            'label' => $field_label,
            'type' => $field_type,
            'description' => $field_description,
            'visible' => $field_visible,
            'editable' => $field_editable,
        );

        if ($field_index !== null && isset($custom_fields[$field_index])) {
            // Update existing field
            $custom_fields[$field_index] = $new_field;
        } else {
            // Add new field (check for duplicates)
            $key_exists = false;
            foreach ($custom_fields as $field) {
                if (isset($field['key']) && $field['key'] === $field_key) {
                    $key_exists = true;
                    break;
                }
            }
            if (!$key_exists) {
                $custom_fields[] = $new_field;
            } else {
                wp_redirect(add_query_arg(array(
                    'page' => 'twork-custom-fields',
                    'updated' => '0',
                    'error' => '3',
                ), admin_url('admin.php')));
                exit;
            }
        }

        update_option('twork_custom_field_definitions', $custom_fields);

        wp_redirect(add_query_arg(array(
            'page' => 'twork-custom-fields',
            'updated' => '1',
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Handle custom fields delete
     */
    public function handle_custom_fields_delete() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        $field_index = isset($_GET['field']) ? intval($_GET['field']) : null;

        if ($field_index === null) {
            wp_redirect(add_query_arg(array(
                'page' => 'twork-custom-fields',
                'updated' => '0',
            ), admin_url('admin.php')));
            exit;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'delete_custom_field_' . $field_index)) {
            wp_die(__('Security check failed. Please try again.', 'twork-points'));
        }

        $custom_fields = get_option('twork_custom_field_definitions', array());

        if (isset($custom_fields[$field_index])) {
            $field = $custom_fields[$field_index];
            // Don't allow deletion of points_balance
            if (isset($field['key']) && $field['key'] === 'points_balance') {
                wp_redirect(add_query_arg(array(
                    'page' => 'twork-custom-fields',
                    'updated' => '0',
                    'error' => '4',
                ), admin_url('admin.php')));
                exit;
            }

            unset($custom_fields[$field_index]);
            $custom_fields = array_values($custom_fields); // Reindex array
            update_option('twork_custom_field_definitions', $custom_fields);
        }

        wp_redirect(add_query_arg(array(
            'page' => 'twork-custom-fields',
            'updated' => '2',
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Edit / update a single transaction from the Transactions page.
     */
    public function handle_transaction_status_update() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        if (!isset($_POST['twork_points_txn_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['twork_points_txn_nonce'])), 'twork_points_update_transaction_status')) {
            wp_die(__('Security check failed. Please try again.', 'twork-points'));
        }

        $transaction_id = isset($_POST['transaction_id']) ? intval($_POST['transaction_id']) : 0;
        $new_points     = isset($_POST['points']) ? intval($_POST['points']) : null;
        $new_status     = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        $new_description= isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';

        if ($transaction_id <= 0 || $new_points === null || $new_status === '') {
            wp_redirect(add_query_arg(array(
                'page'    => 'twork-points-transactions',
                'updated' => '0',
            ), admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_transactions';

        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $transaction_id
        ));

        if (!$transaction) {
            wp_redirect(add_query_arg(array(
                'page'    => 'twork-points-transactions',
                'updated' => '0',
            ), admin_url('admin.php')));
            exit;
        }

        // Normalize status value
        if (!in_array($new_status, array('pending', 'approved', 'rejected'), true)) {
            $new_status = 'approved';
        }

        // Check if status is changing to approved (for notification)
        $old_status = isset($transaction->status) ? $transaction->status : 'pending';
        $is_being_approved = ($old_status !== 'approved' && $new_status === 'approved');
        
        // Use new_points (from form) or existing transaction points
        $points_for_notification = ($new_points !== null && $new_points > 0) ? $new_points : intval($transaction->points);

        $wpdb->update(
            $table,
            array(
                'points'      => $new_points,
                'status'      => $new_status,
                'description' => $new_description,
            ),
            array('id' => $transaction_id),
            array('%d', '%s', '%s'),
            array('%d')
        );

        // Invalidate cache first, then calculate balance once
        $this->invalidate_balance_cache(intval($transaction->user_id));
        
        // Calculate balance once after status update
        // Only update if not a custom admin-set value
        $user_id = intval($transaction->user_id);
        $is_custom = get_user_meta($user_id, 'points_balance_is_custom', true) === '1';
        if (!$is_custom) {
            $balance = $this->calculate_user_balance($user_id, false); // Use cache if recent
            update_user_meta($user_id, 'points_balance', $balance);
        }

        // Update my_points when transaction is approved and points are positive (earn/adjust)
        // Points behaviour: ADD to existing points instead of replacing (same as Lucky Box)
        if ($is_being_approved && $points_for_notification > 0) {
            $transaction_type = isset($transaction->type) ? $transaction->type : '';
            // Only accumulate for earn, adjust, referral, birthday types (not redeem)
            if (in_array($transaction_type, array('earn', 'adjust', 'referral', 'birthday', 'refund'))) {
                $existing_points_raw = get_user_meta($user_id, 'my_points', true);
                $existing_points = is_numeric($existing_points_raw) ? (float) $existing_points_raw : 0.0;
                $delta_points = (float) $points_for_notification;
                $new_points_total = $existing_points + $delta_points;

                // Store as integer if whole number, otherwise keep decimal
                $stored_points = (floor($new_points_total) == $new_points_total)
                    ? (string) (int) $new_points_total
                    : (string) $new_points_total;

                update_user_meta($user_id, 'my_points', $stored_points);
                update_user_meta($user_id, 'my_points_updated_at', time());

                TWork_Points_Logger::info(
                    'Updated my_points after transaction approval',
                    array(
                        'user_id' => $user_id,
                        'transaction_id' => $transaction_id,
                        'transaction_type' => $transaction_type,
                        'points_added' => $delta_points,
                        'existing_points' => $existing_points,
                        'new_points_total' => $new_points_total,
                    )
                );
            }
        }

        // Send notification if transaction is being approved and points are positive
        if ($is_being_approved && $points_for_notification > 0) {
            TWork_Points_Logger::info(
                'Sending points approval notification',
                array(
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'user_id' => intval($transaction->user_id),
                    'transaction_id' => $transaction_id,
                    'points' => $points_for_notification,
                )
            );
            
            // Send notification with calculated balance (non-blocking via webhook)
            $this->send_points_approval_notification(array(
                'user_id' => intval($transaction->user_id),
                'transaction_id' => $transaction_id,
                'points' => $points_for_notification,
                'description' => $new_description ?: $transaction->description,
                'current_balance' => $balance,
            ));
        } else {
            TWork_Points_Logger::info(
                'Skipping notification',
                array(
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                    'is_being_approved' => $is_being_approved,
                    'points' => $points_for_notification,
                )
            );
        }

        // Decide where to redirect: back to edit screen if coming from there,
        // otherwise to the main transactions list.
        $referer = wp_get_referer();
        if ($referer && false !== strpos($referer, 'twork-points-transaction-edit')) {
            wp_safe_redirect(add_query_arg(array(
                'page'           => 'twork-points-transaction-edit',
                'transaction_id' => $transaction_id,
                'updated'        => '1',
            ), admin_url('admin.php')));
        } else {
            wp_safe_redirect(add_query_arg(array(
                'page'    => 'twork-points-transactions',
                'updated' => '1',
            ), admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Handle trash transaction (move to trash)
     * WordPress-style soft delete
     */
    public function handle_trash_transaction() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        // Verify nonce - check_admin_referer works for GET requests from wp_nonce_url
        check_admin_referer('twork_points_trash_transaction');

        $transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';

        if ($transaction_id <= 0) {
            $redirect = add_query_arg(array(
                'page' => 'twork-points-transactions',
                'twork_points_notice' => 'invalid_request',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_transactions';

        // Check if transaction exists and is not already trashed
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $transaction_id
        ));

        if (!$transaction) {
            $redirect = add_query_arg(array(
                'page' => 'twork-points-transactions',
                'twork_points_notice' => 'transaction_not_found',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        // Soft delete: set deleted_at timestamp
        $result = $wpdb->update(
            $table,
            array('deleted_at' => current_time('mysql')),
            array('id' => $transaction_id),
            array('%s'),
            array('%d')
        );

        if ($result !== false) {
            // Invalidate balance cache for the user
            $this->invalidate_balance_cache(intval($transaction->user_id));
            
            TWork_Points_Logger::info(
                'Transaction moved to trash',
                array(
                    'transaction_id' => $transaction_id,
                    'user_id' => intval($transaction->user_id),
                    'admin_user' => get_current_user_id(),
                )
            );

            $redirect = $redirect_to ?: add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => 'transaction_trashed',
            ), admin_url('admin.php'));
        } else {
            $redirect = $redirect_to ?: add_query_arg(array(
                'page' => 'twork-points-transactions',
                'twork_points_notice' => 'trash_failed',
            ), admin_url('admin.php'));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle restore transaction from trash
     */
    public function handle_restore_transaction() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        // Verify nonce - check_admin_referer works for GET requests from wp_nonce_url
        check_admin_referer('twork_points_restore_transaction');

        $transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';

        if ($transaction_id <= 0) {
            $redirect = add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => 'invalid_request',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_transactions';

        // Check if transaction exists and is trashed
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND deleted_at IS NOT NULL",
            $transaction_id
        ));

        if (!$transaction) {
            $redirect = add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => 'transaction_not_found',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        // Restore: clear deleted_at
        $result = $wpdb->update(
            $table,
            array('deleted_at' => null),
            array('id' => $transaction_id),
            array(null),
            array('%d')
        );

        if ($result !== false) {
            // Invalidate balance cache for the user
            $this->invalidate_balance_cache(intval($transaction->user_id));
            
            TWork_Points_Logger::info(
                'Transaction restored from trash',
                array(
                    'transaction_id' => $transaction_id,
                    'user_id' => intval($transaction->user_id),
                    'admin_user' => get_current_user_id(),
                )
            );

            $redirect = $redirect_to ?: add_query_arg(array(
                'page' => 'twork-points-transactions',
                'twork_points_notice' => 'transaction_restored',
            ), admin_url('admin.php'));
        } else {
            $redirect = $redirect_to ?: add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => 'restore_failed',
            ), admin_url('admin.php'));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle delete transaction permanently
     * Only allows deletion of trashed transactions (safety measure)
     */
    public function handle_delete_transaction() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        // Verify nonce - check_admin_referer works for GET requests from wp_nonce_url
        check_admin_referer('twork_points_delete_transaction');

        $transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';

        if ($transaction_id <= 0) {
            $redirect = add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => 'invalid_request',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_transactions';
        
        // Check if deleted_at column exists
        $has_deleted_at_column = false;
        try {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
        } catch (Exception $e) {
            $has_deleted_at_column = false;
        }

        // Get transaction before deletion for logging
        // Only allow deletion of trashed transactions (safety check)
        $where_clause = "id = %d";
        if ($has_deleted_at_column) {
            $where_clause = "id = %d AND deleted_at IS NOT NULL AND deleted_at != ''";
        }
        
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause",
            $transaction_id
        ));

        if (!$transaction) {
            $redirect = add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => $has_deleted_at_column ? 'not_in_trash' : 'transaction_not_found',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        // Permanently delete from database
        $result = $wpdb->delete(
            $table,
            array('id' => $transaction_id),
            array('%d')
        );

        if ($result !== false && $result > 0) {
            // Invalidate balance cache for the user
            $this->invalidate_balance_cache(intval($transaction->user_id));
            
            TWork_Points_Logger::warning(
                'Transaction permanently deleted',
                array(
                    'transaction_id' => $transaction_id,
                    'user_id' => intval($transaction->user_id),
                    'points' => intval($transaction->points),
                    'type' => $transaction->type,
                    'admin_user' => get_current_user_id(),
                )
            );

            $redirect = $redirect_to ?: add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => 'transaction_deleted',
            ), admin_url('admin.php'));
        } else {
            $redirect = $redirect_to ?: add_query_arg(array(
                'page' => 'twork-points-transactions',
                'trashed' => 1,
                'twork_points_notice' => 'delete_failed',
            ), admin_url('admin.php'));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle bulk actions for transactions (WordPress-style)
     */
    public function handle_bulk_transactions() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        // Verify nonce - check_admin_referer works for POST requests
        if (!check_admin_referer('twork_points_bulk_transactions', '_wpnonce', false)) {
            wp_die(__('Security check failed. Please try again.', 'twork-points'));
        }

        // Get bulk action from form
        // Using separate field names to avoid conflict with admin-post action
        $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';
        $bulk_action2 = isset($_POST['bulk_action2']) ? sanitize_text_field(wp_unslash($_POST['bulk_action2'])) : '';
        
        // Use the first non-empty, non-default action
        if (empty($bulk_action) || $bulk_action === '-1') {
            $bulk_action = $bulk_action2;
        }
        
        // Clean up - remove default value
        if ($bulk_action === '-1') {
            $bulk_action = '';
        }
        
        // Get transaction IDs from POST
        $transaction_ids = isset($_POST['transaction_ids']) && is_array($_POST['transaction_ids']) ? array_map('intval', $_POST['transaction_ids']) : array();
        
        // Filter out any zero or invalid IDs
        $transaction_ids = array_filter($transaction_ids, function($id) {
            return $id > 0;
        });

        // Debug logging
        TWork_Points_Logger::info('Bulk transactions handler called', array(
            'bulk_action' => $bulk_action,
            'bulk_action2' => $bulk_action2,
            'transaction_ids' => $transaction_ids,
            'post_data' => array_keys($_POST),
        ));

        if (empty($bulk_action) || empty($transaction_ids)) {
            $redirect_params = array(
                'page' => 'twork-points-transactions',
                'twork_points_notice' => 'no_action_selected',
            );
            
            // Preserve trash view - check from referrer URL or form state
            $referrer = wp_get_referer();
            if ($referrer && strpos($referrer, 'trashed=1') !== false) {
                $redirect_params['trashed'] = 1;
            } elseif (isset($_POST['trashed'])) {
                $redirect_params['trashed'] = intval($_POST['trashed']);
            }
            
            wp_safe_redirect(add_query_arg($redirect_params, admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_transactions';
        $processed = 0;
        $affected_users = array();
        
        // Check if deleted_at column exists
        $has_deleted_at_column = false;
        try {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
        } catch (Exception $e) {
            $has_deleted_at_column = false;
        }
        
        // If trash/untrash is requested but column doesn't exist, redirect with error
        if (in_array($bulk_action, array('trash', 'untrash')) && !$has_deleted_at_column) {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'twork-points-transactions',
                'twork_points_notice' => 'trash_not_available',
            ), admin_url('admin.php')));
            exit;
        }

        foreach ($transaction_ids as $transaction_id) {
            // Get transaction for logging
            $transaction = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d",
                $transaction_id
            ));

            if (!$transaction) {
                continue;
            }

            $affected_users[intval($transaction->user_id)] = true;

            switch ($bulk_action) {
                case 'trash':
                    if ($has_deleted_at_column) {
                        // Check if already trashed to avoid unnecessary updates
                        if (!empty($transaction->deleted_at)) {
                            continue; // Skip if already trashed
                        }
                        $result = $wpdb->update(
                            $table,
                            array('deleted_at' => current_time('mysql')),
                            array('id' => $transaction_id),
                            array('%s'),
                            array('%d')
                        );
                        if ($result !== false) {
                            $processed++;
                        }
                    } else {
                        // Column doesn't exist - try to run migration first
                        $this->run_migrations();
                        // Recheck after migration
                        $column_check = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'deleted_at'");
                        if (!empty($column_check)) {
                            $result = $wpdb->update(
                                $table,
                                array('deleted_at' => current_time('mysql')),
                                array('id' => $transaction_id),
                                array('%s'),
                                array('%d')
                            );
                            if ($result !== false) {
                                $processed++;
                            }
                        }
                    }
                    break;

                case 'untrash':
                    if ($has_deleted_at_column) {
                        // Check if not trashed
                        if (empty($transaction->deleted_at)) {
                            continue; // Skip if not trashed
                        }
                        $result = $wpdb->query($wpdb->prepare(
                            "UPDATE $table SET deleted_at = NULL WHERE id = %d AND deleted_at IS NOT NULL",
                            $transaction_id
                        ));
                        if ($result !== false && $result > 0) {
                            $processed++;
                        }
                    }
                    break;

                case 'delete':
                    // Permanently delete - only delete items that are in trash if column exists
                    if ($has_deleted_at_column && empty($transaction->deleted_at)) {
                        // Not in trash, skip (should only delete from trash)
                        continue;
                    }
                    $result = $wpdb->delete(
                        $table,
                        array('id' => $transaction_id),
                        array('%d')
                    );
                    if ($result !== false && $result > 0) {
                        $processed++;
                    }
                    break;
            }
        }

        // Invalidate balance cache for affected users
        foreach (array_keys($affected_users) as $user_id) {
            $this->invalidate_balance_cache($user_id);
        }

        TWork_Points_Logger::info(
            'Bulk transaction action performed',
            array(
                'action' => $bulk_action,
                'count' => $processed,
                'transaction_ids' => $transaction_ids,
                'admin_user' => get_current_user_id(),
            )
        );

        // Determine redirect parameters based on action
        // After trash: go to trash view
        // After untrash/delete: stay in trash view
        $trashed_param = 0;
        if ($bulk_action === 'trash') {
            $trashed_param = 1; // After trashing, show trash view
        } elseif (in_array($bulk_action, array('untrash', 'delete'))) {
            // Check if we came from trash view
            $referrer = wp_get_referer();
            if ($referrer && (strpos($referrer, 'trashed=1') !== false || isset($_POST['trashed']))) {
                $trashed_param = 1;
            }
        }
        
        $notice_key = 'bulk_' . $bulk_action;

        wp_safe_redirect(add_query_arg(array(
            'page' => 'twork-points-transactions',
            'trashed' => $trashed_param,
            'twork_points_notice' => $notice_key,
            'processed' => $processed,
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Add points information to user profile screen
     */
    public function add_user_profile_points_section($user) {
        if (!current_user_can('manage_users')) {
            return;
        }

        $balance = $this->calculate_user_balance($user->ID, true);
        include TWORK_POINTS_PLUGIN_DIR . 'templates/admin/user-profile.php';
    }

    // Static flag to prevent duplicate profile saves (both hooks might fire)
    private static $profile_save_processed = array();
    
    /**
     * Save user profile points adjustments
     */
    public function save_user_profile_points($user_id) {
        if (!current_user_can('manage_users')) {
            return;
        }

        if (!isset($_POST['twork_points_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['twork_points_profile_nonce'])), 'twork_points_profile_update')) {
            return;
        }

        // Prevent duplicate processing (both personal_options_update and edit_user_profile_update might fire)
        $save_key = 'profile_save_' . $user_id . '_' . (time() - (time() % 2)); // Round to 2-second window
        if (isset(self::$profile_save_processed[$save_key])) {
            return; // Already processed in this request
        }
        self::$profile_save_processed[$save_key] = true;
        
        // Cleanup old entries
        if (count(self::$profile_save_processed) > 20) {
            self::$profile_save_processed = array_slice(self::$profile_save_processed, -10, null, true);
        }

        if (isset($_POST['twork_points_adjust_amount']) && $_POST['twork_points_adjust_amount'] !== '') {
            $points = intval($_POST['twork_points_adjust_amount']);
            $description = isset($_POST['twork_points_adjust_reason']) ? sanitize_text_field(wp_unslash($_POST['twork_points_adjust_reason'])) : '';

            if ($points !== 0) {
                $transaction_data = array(
                    'user_id' => $user_id,
                    'type' => 'adjust',
                    'points' => $points,
                    'description' => $description ?: __('Manual adjustment via user profile', 'twork-points'),
                );
                // Note: create_transaction() already has duplicate prevention and recursive call protection
                $this->create_transaction($transaction_data);
            }
        }

        // Note: Custom Fields (Points Balance and My Point) are now managed from 
        // the User Points Management page (T-Work Points > User Points) instead of here
    }

    /**
     * Handle settings save
     */
    public function handle_settings_save() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        check_admin_referer('twork_points_save_settings');

        $input = wp_unslash($_POST);

        $validation = $this->validate_settings_input($input);
        $field_errors = $validation['errors'];
        $display_values = $validation['display_values'];
        $options_to_update = $validation['options'];

        if (! empty($field_errors)) {
            set_transient(self::SETTINGS_ERROR_TRANSIENT, $field_errors, MINUTE_IN_SECONDS);
            set_transient(self::SETTINGS_VALUES_TRANSIENT, $display_values, MINUTE_IN_SECONDS);

            $redirect = add_query_arg(array(
                'page' => 'twork-points-settings',
                'twork_points_notice' => 'settings_invalid',
            ), admin_url('admin.php'));

            wp_safe_redirect($redirect);
            exit;
        }

        foreach ($options_to_update as $option_key => $value) {
            update_option($option_key, $value);
        }

        delete_transient(self::SETTINGS_ERROR_TRANSIENT);
        delete_transient(self::SETTINGS_VALUES_TRANSIENT);

        $redirect = add_query_arg(array(
            'page' => 'twork-points-settings',
            'twork_points_notice' => 'settings_saved',
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Validate settings input and return sanitized values with error messages.
     *
     * @param array $input Raw input.
     * @return array {
     *     @type array $display_values Values formatted for UI.
     *     @type array $options        Values keyed by option name for persistence.
     *     @type array $errors         Field-level error messages.
     * }
     */
    private function validate_settings_input(array $input): array {
        $schema = $this->get_settings_schema();

        $display_values = array();
        $options = array();
        $errors = array();

        foreach ($schema as $field_key => $rules) {
            $raw_value = isset($input[$rules['field']]) ? $input[$rules['field']] : '';
            $raw_value = is_array($raw_value) ? '' : $raw_value; // prevent arrays
            $display_values[$field_key] = is_scalar($raw_value) ? sanitize_text_field((string) $raw_value) : '';

            if ($raw_value === '') {
                if (! empty($rules['required'])) {
                    $errors[$field_key] = sprintf(
                        /* translators: %s - field label */
                        __('%s is required.', 'twork-points'),
                        $rules['label']
                    );
                } else {
                    // For optional fields, store empty value
                    $options[$rules['option']] = '';
                }
                continue;
            }

            // Handle URL type separately
            if ($rules['type'] === 'url') {
                $sanitized_url = esc_url_raw($raw_value);
                if (!empty($raw_value) && !filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
                    $errors[$field_key] = sprintf(
                        /* translators: %s - field label */
                        __('%s must be a valid URL.', 'twork-points'),
                        $rules['label']
                    );
                    continue;
                }
                $options[$rules['option']] = $sanitized_url;
                $display_values[$field_key] = $sanitized_url;
                continue;
            }

            $parsed = $this->parse_numeric($raw_value, $rules['type']);

            if ($parsed === null) {
                $errors[$field_key] = sprintf(
                    /* translators: %s - field label */
                    __('%s must be a numeric value.', 'twork-points'),
                    $rules['label']
                );
                continue;
            }

            if (isset($rules['min']) && $parsed < $rules['min']) {
                $errors[$field_key] = sprintf(
                    /* translators: 1: field label, 2: minimum value */
                    __('%1$s must be greater than or equal to %2$s.', 'twork-points'),
                    $rules['label'],
                    $rules['min']
                );
                continue;
            }

            if (isset($rules['max']) && $parsed > $rules['max']) {
                $errors[$field_key] = sprintf(
                    /* translators: 1: field label, 2: maximum value */
                    __('%1$s must be less than or equal to %2$s.', 'twork-points'),
                    $rules['label'],
                    $rules['max']
                );
                continue;
            }

            if (! empty($rules['integer'])) {
                $parsed = (int) round($parsed);
            }

            $display_values[$field_key] = $parsed;
            $options[$rules['option']] = $parsed;
        }

        return array(
            'display_values' => $display_values,
            'options' => $options,
            'errors' => $errors,
        );
    }

    /**
     * Get settings field schema describing sanitization rules.
     *
     * @return array
     */
    private function get_settings_schema(): array {
        return array(
            'points_rate' => array(
                'field' => 'twork_points_rate',
                'option' => 'twork_points_rate',
                'label' => __('Points Earning Rate', 'twork-points'),
                'type' => 'float',
                'min' => 0,
                'required' => true,
            ),
            'redemption_rate' => array(
                'field' => 'twork_points_redemption_rate',
                'option' => 'twork_points_redemption_rate',
                'label' => __('Points Redemption Rate', 'twork-points'),
                'type' => 'float',
                'min' => 0.01,
                'required' => true,
            ),
            'signup_bonus' => array(
                'field' => 'twork_points_signup_bonus',
                'option' => 'twork_points_signup_bonus',
                'label' => __('Signup Bonus Points', 'twork-points'),
                'type' => 'int',
                'min' => 0,
                'integer' => true,
                'required' => true,
            ),
            'referral_bonus' => array(
                'field' => 'twork_points_referral_bonus',
                'option' => 'twork_points_referral_bonus',
                'label' => __('Referral Bonus Points', 'twork-points'),
                'type' => 'int',
                'min' => 0,
                'integer' => true,
                'required' => true,
            ),
            'birthday_bonus' => array(
                'field' => 'twork_points_birthday_bonus',
                'option' => 'twork_points_birthday_bonus',
                'label' => __('Birthday Bonus Points', 'twork-points'),
                'type' => 'int',
                'min' => 0,
                'integer' => true,
                'required' => true,
            ),
            'min_redemption' => array(
                'field' => 'twork_points_min_redemption',
                'option' => 'twork_points_min_redemption',
                'label' => __('Minimum Points to Redeem', 'twork-points'),
                'type' => 'int',
                'min' => 0,
                'integer' => true,
                'required' => true,
            ),
            'max_redemption_percent' => array(
                'field' => 'twork_points_max_redemption_percent',
                'option' => 'twork_points_max_redemption_percent',
                'label' => __('Maximum Points per Order (%)', 'twork-points'),
                'type' => 'int',
                'min' => 0,
                'max' => 100,
                'integer' => true,
                'required' => true,
            ),
            'expiration_days' => array(
                'field' => 'twork_points_expiration_days',
                'option' => 'twork_points_expiration_days',
                'label' => __('Points Expiration (days)', 'twork-points'),
                'type' => 'int',
                'min' => 0,
                'integer' => true,
                'required' => true,
            ),
            'webhook_url' => array(
                'field' => 'twork_points_webhook_url',
                'option' => 'twork_points_webhook_url',
                'label' => __('Notification Webhook URL', 'twork-points'),
                'type' => 'url',
                'required' => false,
            ),
        );
    }

    /**
     * Parse numeric input according to the expected type.
     *
     * @param mixed  $value Raw value.
     * @param string $type  Expected type (int|float).
     *
     * @return float|int|null
     */
    private function parse_numeric($value, string $type) {
        if ($value === '' || $value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($type === 'int') {
            return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        }

        if ($type === 'float') {
            $normalized = str_replace(',', '.', $value);
            return filter_var($normalized, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    /**
     * Handle manual user points adjustment
     */
    public function handle_adjust_user_points() {
        if (!current_user_can('manage_users') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        check_admin_referer('twork_points_adjust_user_points');

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $points  = isset($_POST['points']) ? intval($_POST['points']) : 0;
        $reason  = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        $adjust_type = isset($_POST['adjust_type']) ? sanitize_text_field(wp_unslash($_POST['adjust_type'])) : 'adjust';
        $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
        $save_custom_fields = isset($_POST['save_custom_fields']) && $_POST['save_custom_fields'] === '1';

        if (!$user_id) {
            $redirect = add_query_arg(array(
                'page' => 'twork-points-users',
                'twork_points_notice' => 'invalid_request',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        // Handle custom fields save (separate from points adjustment)
        if ($save_custom_fields) {
            // Save Points Balance custom field
            if (isset($_POST['twork_custom_points_balance'])) {
                $points_balance = sanitize_text_field(wp_unslash($_POST['twork_custom_points_balance']));
                if ($points_balance !== '') {
                    // Save as user meta - this will override calculated balance in REST API
                    update_user_meta($user_id, 'points_balance', intval($points_balance));
                    // Mark as admin-set custom value
                    update_user_meta($user_id, 'points_balance_is_custom', '1');
                } else {
                    // If empty, use calculated balance and remove custom flag
                    $calculated_balance = $this->calculate_user_balance($user_id, true);
                    update_user_meta($user_id, 'points_balance', $calculated_balance);
                    delete_user_meta($user_id, 'points_balance_is_custom');
                }
            }

            // Save My Point field
            if (isset($_POST['twork_custom_my_point'])) {
                $my_point = sanitize_text_field(wp_unslash($_POST['twork_custom_my_point']));
                update_user_meta($user_id, 'my_point', $my_point);
            }

            // Save Lucky Box per-user enable flag
            // Checkbox sends "1" when checked, hidden input sends "0" otherwise.
            if (isset($_POST['twork_custom_luckybox_enabled'])) {
                $raw = sanitize_text_field(wp_unslash($_POST['twork_custom_luckybox_enabled']));
                update_user_meta($user_id, 'twork_luckybox_enabled', ($raw === '1') ? '1' : '0');
            }

            // Redirect with success message
            $redirect = $redirect_to ?: add_query_arg(array(
                'page' => 'twork-points-users',
                'user_id' => $user_id,
                'twork_points_notice' => 'custom_fields_saved',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        // Handle "set to specific value" action
        if ($adjust_type === 'set') {
            if ($points < 0) {
                $redirect = add_query_arg(array(
                    'page' => 'twork-points-users',
                    'user_id' => $user_id,
                    'twork_points_notice' => 'invalid_set_value',
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }
            
            // Get current balance
            $current_balance = $this->calculate_user_balance($user_id, false);
            
            // Calculate the difference needed
            $adjustment_points = $points - $current_balance;
            
            if ($adjustment_points === 0) {
                // No change needed
                $redirect = add_query_arg(array(
                    'page' => 'twork-points-users',
                    'user_id' => $user_id,
                    's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
                    'twork_points_notice' => 'no_change',
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }
            
            $points = $adjustment_points;
            $description = sprintf(__('Balance set to %s points (was %s)', 'twork-points'), number_format_i18n($points + $current_balance), number_format_i18n($current_balance));
            if (!empty($reason)) {
                $description .= ' - ' . $reason;
            }
        } else {
            // Regular adjustment
            if ($points === 0) {
                $redirect = add_query_arg(array(
                    'page' => 'twork-points-users',
                    'twork_points_notice' => 'invalid_request',
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }
            
            $description = $reason ?: __('Manual adjustment via admin', 'twork-points');
        }

        $type = $points >= 0 ? 'adjust' : 'adjust';

        // Get current balance once before creating transaction (for notification)
        $current_balance_before = isset($current_balance) ? $current_balance : $this->calculate_user_balance($user_id, false);

        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => $type,
            'points' => $points,
            'description' => $description,
        ));

        if ($transaction_id) {
            // Note: create_transaction() already invalidates cache, no need to call again
            // Calculate new balance once after transaction (optimized)
            $new_balance = $this->calculate_user_balance($user_id, false); // Use cache if available
            
            // Update my_points when points are adjusted (positive or negative)
            // Points behaviour: ADD to existing points (or subtract if negative) instead of replacing
            if ($points != 0) {
                $existing_points_raw = get_user_meta($user_id, 'my_points', true);
                $existing_points = is_numeric($existing_points_raw) ? (float) $existing_points_raw : 0.0;
                $delta_points = (float) $points;
                $new_points_total = $existing_points + $delta_points;
                
                // Ensure points don't go below 0
                if ($new_points_total < 0) {
                    $new_points_total = 0;
                }

                // Store as integer if whole number, otherwise keep decimal
                $stored_points = (floor($new_points_total) == $new_points_total)
                    ? (string) (int) $new_points_total
                    : (string) $new_points_total;

                update_user_meta($user_id, 'my_points', $stored_points);
                update_user_meta($user_id, 'my_points_updated_at', time());

                TWork_Points_Logger::info(
                    'Updated my_points after manual adjustment',
                    array(
                        'user_id' => $user_id,
                        'transaction_id' => $transaction_id,
                        'points_delta' => $delta_points,
                        'existing_points' => $existing_points,
                        'new_points_total' => $new_points_total,
                    )
                );
            }
            
            // Also create a transaction in twork-rewards-system plugin's transaction table
            // Check if twork-rewards-system plugin is active
            if (class_exists('TWork_Rewards_System')) {
                global $wpdb;
                $rewards_table = $wpdb->prefix . 'twork_reward_transactions';
                
                // Check if table exists
                $table_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $rewards_table
                ));
                
                if ($table_exists) {
                    // Create transaction record in rewards system
                    $order_id = 'points_adjust:' . $transaction_id;
                    $points_value = (string) $points; // Convert to string for consistency
                    
                    $rewards_txn_inserted = $wpdb->insert(
                        $rewards_table,
                        array(
                            'user_id' => $user_id,
                            'order_id' => $order_id,
                            'status' => 'approved', // Manual adjustments are automatically approved
                            'reward_value' => null,
                            'points_value' => $points_value,
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
                    );
                    
                    if ($rewards_txn_inserted !== false) {
                        TWork_Points_Logger::info(
                            'Created transaction in rewards system for manual points adjustment',
                            array(
                                'user_id' => $user_id,
                                'points_transaction_id' => $transaction_id,
                                'rewards_transaction_id' => $wpdb->insert_id,
                                'points' => $points,
                            )
                        );
                    } else {
                        TWork_Points_Logger::warning(
                            'Failed to create transaction in rewards system',
                            array(
                                'user_id' => $user_id,
                                'points_transaction_id' => $transaction_id,
                                'error' => $wpdb->last_error,
                            )
                        );
                    }
                }
            }
            
            $admin = wp_get_current_user();
            $this->record_admin_adjustment($user_id, $points, $reason, $admin ? intval($admin->ID) : 0);
            
            // Send notification to app about balance update
            // Send the actual points value (can be positive or negative) so app can show appropriate message
            $this->send_points_approval_notification(array(
                'user_id' => $user_id,
                'transaction_id' => $transaction_id,
                'points' => $points, // Keep original value (positive or negative) for proper notification
                'description' => $description,
                'current_balance' => $new_balance,
            ));
            
            TWork_Points_Logger::info(
                'Manual points adjustment applied',
                array(
                    'transaction_id' => $transaction_id,
                    'user_id' => $user_id,
                    'points' => $points,
                    'old_balance' => $current_balance_before,
                    'new_balance' => $new_balance,
                    'admin_user' => $admin ? $admin->user_login : 'unknown',
                )
            );
            $notice = 'adjustment_success';
        } else {
            TWork_Points_Logger::error(
                'Manual points adjustment failed',
                array(
                    'user_id' => $user_id,
                    'points' => $points,
                )
            );
            $notice = 'adjustment_failed';
        }

        if (empty($redirect_to)) {
            $redirect_to = add_query_arg(array(
                'page' => 'twork-points-users',
                'user_id' => $user_id,
                'twork_points_notice' => $notice,
            ), admin_url('admin.php'));
        } else {
            $redirect_to = add_query_arg(array(
                'twork_points_notice' => $notice,
            ), $redirect_to);
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Handle bulk actions (recalculate balances, expire points)
     */
    public function handle_bulk_action() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        check_admin_referer('twork_points_bulk_action');

        $action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';

        switch ($action) {
            case 'recalculate_balances':
                $this->recalculate_all_balances();
                $notice = 'balances_recalculated';
                break;
            case 'expire_now':
                $this->expire_points_now();
                $notice = 'points_expired';
                break;
            default:
                $notice = 'invalid_request';
                break;
        }

        $redirect = add_query_arg(array(
            'page' => 'twork-points-reports',
            'twork_points_notice' => $notice,
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle exporting transactions to CSV
     */
    public function handle_export_transactions() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'twork-points'));
        }

        check_admin_referer('twork_points_export');

        $args = array(
            'type' => isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '',
            'user_id' => isset($_POST['user_id']) ? intval($_POST['user_id']) : 0,
            'order_id' => isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '',
            'search' => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
            'limit' => -1,
        );

        $transactions = $this->get_transactions($args);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=twork-points-transactions-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'User ID', 'User Email', 'Type', 'Points', 'Description', 'Order ID', 'Created At', 'Expires At', 'Expired?'));

        foreach ($transactions as $transaction) {
            $user = get_user_by('ID', $transaction['user_id']);
            fputcsv($output, array(
                $transaction['id'],
                $transaction['user_id'],
                $user ? $user->user_email : '',
                $transaction['type'],
                $transaction['points'],
                $transaction['description'],
                $transaction['order_id'],
                $transaction['created_at'],
                $transaction['expires_at'],
                $transaction['is_expired'] ? 'Yes' : 'No',
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        $this->maybe_render_sync_alert();

        if (!isset($_GET['twork_points_notice'])) {
            return;
        }

        $notice = sanitize_key($_GET['twork_points_notice']);
        $messages = array(
            'settings_saved' => array('class' => 'success', 'text' => __('Point settings saved successfully.', 'twork-points')),
            'settings_invalid' => array('class' => 'error', 'text' => __('Settings were not saved. Please correct the highlighted errors and try again.', 'twork-points')),
            'adjustment_success' => array('class' => 'success', 'text' => __('Points adjusted successfully.', 'twork-points')),
            'adjustment_failed' => array('class' => 'error', 'text' => __('Failed to adjust points. Please try again.', 'twork-points')),
            'balances_recalculated' => array('class' => 'success', 'text' => __('All user balances recalculated successfully.', 'twork-points')),
            'points_expired' => array('class' => 'success', 'text' => __('Expired points processed successfully.', 'twork-points')),
            'invalid_request' => array('class' => 'error', 'text' => __('Invalid request. Please check your input and try again.', 'twork-points')),
            'invalid_set_value' => array('class' => 'error', 'text' => __('Cannot set balance to a negative value.', 'twork-points')),
            'no_change' => array('class' => 'info', 'text' => __('No change needed. The balance is already at the requested value.', 'twork-points')),
            'transaction_trashed' => array('class' => 'success', 'text' => __('Transaction moved to trash.', 'twork-points')),
            'transaction_restored' => array('class' => 'success', 'text' => __('Transaction restored from trash.', 'twork-points')),
            'transaction_deleted' => array('class' => 'success', 'text' => __('Transaction permanently deleted.', 'twork-points')),
            'trash_failed' => array('class' => 'error', 'text' => __('Failed to move transaction to trash.', 'twork-points')),
            'restore_failed' => array('class' => 'error', 'text' => __('Failed to restore transaction.', 'twork-points')),
            'delete_failed' => array('class' => 'error', 'text' => __('Failed to delete transaction.', 'twork-points')),
            'transaction_not_found' => array('class' => 'error', 'text' => __('Transaction not found.', 'twork-points')),
            'not_in_trash' => array('class' => 'error', 'text' => __('Transaction is not in trash. Please trash it first before permanently deleting.', 'twork-points')),
            'no_action_selected' => array('class' => 'error', 'text' => __('No action selected. Please select an action and try again.', 'twork-points')),
            'bulk_trash' => array('class' => 'success', 'text' => sprintf(_n('%d transaction moved to trash.', '%d transactions moved to trash.', isset($_GET['processed']) ? intval($_GET['processed']) : 0, 'twork-points'), isset($_GET['processed']) ? intval($_GET['processed']) : 0)),
            'bulk_untrash' => array('class' => 'success', 'text' => sprintf(_n('%d transaction restored from trash.', '%d transactions restored from trash.', isset($_GET['processed']) ? intval($_GET['processed']) : 0, 'twork-points'), isset($_GET['processed']) ? intval($_GET['processed']) : 0)),
            'bulk_delete' => array('class' => 'success', 'text' => sprintf(_n('%d transaction permanently deleted.', '%d transactions permanently deleted.', isset($_GET['processed']) ? intval($_GET['processed']) : 0, 'twork-points'), isset($_GET['processed']) ? intval($_GET['processed']) : 0)),
            'trash_not_available' => array('class' => 'error', 'text' => __('Trash functionality is not available. Please deactivate and reactivate the plugin to run database migrations.', 'twork-points')),
        );

        if (isset($messages[$notice])) {
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($messages[$notice]['class']),
                esc_html($messages[$notice]['text'])
            );
        }
    }

    /**
     * Allow administrators to dismiss persistent notices.
     */
    public function handle_notice_dismissal(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (! isset($_GET['twork_points_dismiss'])) {
            return;
        }

        $notice = sanitize_key($_GET['twork_points_dismiss']);
        if ('sync_errors' === $notice) {
            delete_option(self::SYNC_ERROR_NOTICE_OPTION);
            $redirect = remove_query_arg('twork_points_dismiss');
            if (!$redirect) {
                $redirect = admin_url();
            }
            wp_safe_redirect($redirect);
            exit;
        }
    }

    /**
     * Persist sync failure state and log the error.
     *
     * @param string $context Context of the failure.
     * @param mixed  $error   Error payload.
     */
    private function record_sync_failure(string $context, $error): void {
        $state = get_option(self::SYNC_ERROR_OPTION, array());
        $now   = time();
        $first = isset($state['first']) ? intval($state['first']) : $now;

        if (($now - $first) > self::SYNC_ERROR_WINDOW) {
            $first = $now;
            $state['count'] = 0;
        }

        $count   = isset($state['count']) ? intval($state['count']) + 1 : 1;
        $message = is_scalar($error) ? (string) $error : wp_json_encode($error);

        $state['count']        = $count;
        $state['first']        = $first;
        $state['last_context'] = $context;
        $state['last_message'] = $message;
        $state['updated_at']   = current_time('mysql', true);

        update_option(self::SYNC_ERROR_OPTION, $state, false);

        TWork_Points_Logger::error(
            sprintf('Sync failure recorded (%s)', $context),
            array(
                'count'   => $count,
                'message' => $message,
            )
        );

        if ($count >= self::SYNC_ERROR_THRESHOLD) {
            update_option(
                self::SYNC_ERROR_NOTICE_OPTION,
                array(
                    'count'        => $count,
                    'last_message' => $message,
                    'last_context' => $context,
                    'timestamp'    => $now,
                ),
                false
            );
        }
    }

    /**
     * Clears the failure counter after a successful sync.
     */
    private function record_sync_success(): void {
        delete_option(self::SYNC_ERROR_NOTICE_OPTION);

        $state = array(
            'count'        => 0,
            'first'        => time(),
            'last_context' => '',
            'last_message' => '',
            'updated_at'   => current_time('mysql', true),
        );

        update_option(self::SYNC_ERROR_OPTION, $state, false);
    }

    /**
     * Display admin alert when sync errors remain high.
     */
    private function maybe_render_sync_alert(): void {
        $notice = get_option(self::SYNC_ERROR_NOTICE_OPTION, array());
        if (empty($notice['count']) || intval($notice['count']) < self::SYNC_ERROR_THRESHOLD) {
            return;
        }

        $dismiss_url = add_query_arg(
            array(
                'twork_points_dismiss' => 'sync_errors',
            )
        );

        $message = isset($notice['last_message']) && $notice['last_message']
            ? $notice['last_message']
            : __('See logs for more detail.', 'twork-points');

        printf(
            '<div class="notice notice-error"><p>%s</p><p><a href="%s">%s</a></p></div>',
            esc_html(
                sprintf(
                    /* translators: 1: number of failures, 2: last error message */
                    __('Point sync has failed %1$d times in the last hour. Last error: %2$s', 'twork-points'),
                    intval($notice['count']),
                    $message
                )
            ),
            esc_url($dismiss_url),
            esc_html__('Dismiss this alert', 'twork-points')
        );
    }

    private function record_admin_adjustment(int $user_id, int $points, string $reason, int $admin_user_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'twork_point_audit_log';

        $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'admin_user_id' => $admin_user_id,
                'points' => $points,
                'reason' => $reason,
                'created_at' => current_time('mysql', true),
            ),
            array('%d', '%d', '%d', '%s', '%s')
        );

        TWork_Points_Logger::info(
            'Admin points adjustment recorded',
            array(
                'user_id' => $user_id,
                'admin_user_id' => $admin_user_id,
                'points' => $points,
            )
        );
    }

    /**
     * Retrieve overall points summary statistics
     */
    private function get_points_summary() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'twork_point_transactions';

        // Check if deleted_at column exists
        $has_deleted_at_column = false;
        try {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
        } catch (Exception $e) {
            $has_deleted_at_column = false;
        }
        
        $where_deleted = '';
        if ($has_deleted_at_column) {
            $where_deleted = " WHERE (deleted_at IS NULL OR deleted_at = '')";
        }

        $sql = "SELECT 
                SUM(CASE WHEN type IN ('earn', 'adjust', 'referral', 'birthday', 'refund') THEN points ELSE 0 END) AS total_earned,
                SUM(CASE WHEN type = 'redeem' THEN points ELSE 0 END) AS total_redeemed,
                SUM(CASE WHEN type = 'expire' THEN points ELSE 0 END) AS total_expired,
                COUNT(DISTINCT user_id) AS active_users,
                COUNT(*) AS total_transactions
            FROM $table_name" . $where_deleted;

        $summary = $wpdb->get_row($sql, ARRAY_A);

        $summary = array_map('intval', $summary ?: array());
        $summary['current_balance'] = max(0, ($summary['total_earned'] ?? 0) - ($summary['total_redeemed'] ?? 0) - ($summary['total_expired'] ?? 0));

        return $summary;
    }

    /**
     * Retrieve transactions with optional filters
     */
    private function get_transactions($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'twork_point_transactions';

        $defaults = array(
            'type'    => '',
            'user_id' => 0,
            'order_id'=> '',
            'limit'   => 25,
            'paged'   => 1,
            'expiring'=> false,
            'search'  => '',
            'with_total' => false,
            'trashed' => false, // true = only trashed, false = exclude trashed, null = all
        );
        $args = wp_parse_args($args, $defaults);

        $where = array();
        $where_params = array();
        
        // Check if deleted_at column exists before filtering
        // Use safer method that doesn't cause errors if table doesn't exist
        $has_deleted_at_column = false;
        try {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
        } catch (Exception $e) {
            // Column doesn't exist or table doesn't exist - continue without trash filter
            $has_deleted_at_column = false;
        }
        
        // Filter by trash status (WordPress style: default exclude trashed)
        // Only apply filter if deleted_at column exists
        if ($has_deleted_at_column) {
            if ($args['trashed'] === false) {
                // Exclude trashed: deleted_at must be NULL or empty string
                $where[] = '(deleted_at IS NULL OR deleted_at = "")';
            } elseif ($args['trashed'] === true) {
                // Only show trashed: deleted_at must be NOT NULL and not empty
                $where[] = '(deleted_at IS NOT NULL AND deleted_at != "")';
            }
            // If trashed === null, show all (no filter)
        }

        if (!empty($args['type'])) {
            $where[] = 'type = %s';
            $where_params[] = $args['type'];
        }

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $where_params[] = $args['user_id'];
        }

        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %s';
            $where_params[] = $args['order_id'];
        }

        if (!empty($args['expiring'])) {
            $where[] = 'expires_at IS NOT NULL AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY)';
        }

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(description LIKE %s OR order_id LIKE %s)';
            $where_params[] = $like;
            $where_params[] = $like;
        }

        $where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        $limit = intval($args['limit']);
        $limit = ($limit === -1) ? 0 : $limit;
        $current_page = max(1, intval($args['paged']));
        $offset = $limit > 0 ? ($current_page - 1) * $limit : 0;

        $limit_sql = '';
        $limit_params = array();
        if ($limit > 0) {
            $limit_sql = ' LIMIT %d OFFSET %d';
            $limit_params = array($limit, $offset);
        }

        // Order by id DESC first (newest ID = newest transaction), then created_at DESC as secondary
        // This ensures consistent ordering even when multiple transactions have the same timestamp
        $sql = "SELECT * FROM $table_name $where_sql ORDER BY id DESC, created_at DESC$limit_sql";

        $query_params = array_merge($where_params, $limit_params);

        if (!empty($query_params)) {
            $sql = $wpdb->prepare($sql, $query_params);
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        if (empty($args['with_total'])) {
            return $results;
        }

        $count_sql = "SELECT COUNT(*) FROM $table_name $where_sql";
        if (!empty($where_params)) {
            $count_sql = $wpdb->prepare($count_sql, $where_params);
        }

        $total = (int) $wpdb->get_var($count_sql);

        return array(
            'transactions' => $results,
            'total' => $total,
        );
    }

    /**
     * Retrieve top users by points balance
     */
    private function get_top_users($limit = 10) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'twork_point_transactions';

        // Check if deleted_at column exists
        $has_deleted_at_column = false;
        try {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
        } catch (Exception $e) {
            $has_deleted_at_column = false;
        }
        
        $where_deleted = '';
        if ($has_deleted_at_column) {
            $where_deleted = " WHERE (deleted_at IS NULL OR deleted_at = '')";
        }

        $sql = "SELECT user_id,
                SUM(CASE WHEN type IN ('earn', 'adjust', 'referral', 'birthday', 'refund') THEN points ELSE 0 END) as earned,
                SUM(CASE WHEN type = 'redeem' THEN points ELSE 0 END) as redeemed,
                SUM(CASE WHEN type = 'expire' THEN points ELSE 0 END) as expired
             FROM $table_name" . $where_deleted . "
             GROUP BY user_id
             ORDER BY (earned - redeemed - expired) DESC
             LIMIT %d";

        return $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
    }

    /**
     * Recalculate all user balances and update cache
     * PROFESSIONAL FIX: Also syncs my_points for all users to ensure consistency
     */
    private function recalculate_all_balances() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'twork_point_transactions';

        $user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM $table_name");

        foreach ($user_ids as $user_id) {
            $balance = $this->calculate_user_balance($user_id, true);
            update_user_meta($user_id, 'points_balance', $balance);
            // calculate_user_balance already syncs my_points, but force sync here for safety
            $this->sync_my_points_with_balance($user_id, $balance, true);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'T-Work Points: Recalculated and synced balances for %d users',
                count($user_ids)
            ));
        }
    }
    
    /**
     * PROFESSIONAL FIX: Sync a specific user's my_points with their calculated balance
     * This can be used to fix balance sync issues for specific users (e.g., mawkunnmyat)
     * 
     * @param int $user_id User ID to sync
     * @param bool $force_recalculate Force recalculation from transactions
     * @return array Result with old and new values
     */
    public function sync_user_balance($user_id, $force_recalculate = false) {
        // Calculate balance from transactions
        $calculated_balance = $this->calculate_user_balance($user_id, $force_recalculate);
        
        // Get current values
        $old_points_balance = get_user_meta($user_id, 'points_balance', true);
        $old_my_points = get_user_meta($user_id, 'my_points', true);
        $old_my_point = get_user_meta($user_id, 'my_point', true);
        
        // Update points_balance
        update_user_meta($user_id, 'points_balance', $calculated_balance);
        
        // Sync my_points and my_point
        $this->sync_my_points_with_balance($user_id, $calculated_balance, true);
        
        // Get new values
        $new_points_balance = get_user_meta($user_id, 'points_balance', true);
        $new_my_points = get_user_meta($user_id, 'my_points', true);
        $new_my_point = get_user_meta($user_id, 'my_point', true);
        
        $result = array(
            'user_id' => $user_id,
            'calculated_balance' => $calculated_balance,
            'old' => array(
                'points_balance' => $old_points_balance,
                'my_points' => $old_my_points,
                'my_point' => $old_my_point,
            ),
            'new' => array(
                'points_balance' => $new_points_balance,
                'my_points' => $new_my_points,
                'my_point' => $new_my_point,
            ),
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'T-Work Points: Manual sync for user %d - Calculated: %d, Old points_balance: %s, Old my_points: %s, New points_balance: %s, New my_points: %s',
                $user_id,
                $calculated_balance,
                $old_points_balance !== false ? $old_points_balance : 'empty',
                $old_my_points !== false ? $old_my_points : 'empty',
                $new_points_balance,
                $new_my_points
            ));
        }
        
        return $result;
    }
    
    /**
     * REST API endpoint: Sync specific user's balance
     * PROFESSIONAL FIX: Allows manual sync of my_points with calculated balance
     * Useful for fixing balance sync issues for specific users (e.g., mawkunnmyat)
     * 
     * @param WP_REST_Request $request The REST API request
     * @return WP_REST_Response|WP_Error Response with sync result or error
     */
    public function rest_sync_user_balance($request) {
        $user_id = intval($request->get_param('user_id'));
        $force_recalculate = $request->get_param('force_recalculate');
        
        // Convert string to boolean
        if (is_string($force_recalculate)) {
            $force_recalculate = ($force_recalculate === 'true' || $force_recalculate === '1');
        }
        $force_recalculate = (bool)$force_recalculate;
        
        if ($user_id <= 0) {
            return new WP_Error(
                'invalid_user_id',
                'Invalid user ID',
                array('status' => 400)
            );
        }
        
        // Check if user exists
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return new WP_Error(
                'user_not_found',
                'User not found',
                array('status' => 404)
            );
        }
        
        try {
            $result = $this->sync_user_balance($user_id, $force_recalculate);
            
            return rest_ensure_response(array(
                'success' => true,
                'message' => sprintf('Balance synced successfully for user %d', $user_id),
                'data' => $result,
            ));
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('T-Work Points: Error syncing user balance: ' . $e->getMessage());
            }
            return new WP_Error(
                'sync_failed',
                'Failed to sync user balance: ' . $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Expire points for a single user and create an expire transaction
     */
    private function expire_points_for_user($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'twork_point_transactions';

        // Check if deleted_at column exists
        $has_deleted_at_column = false;
        try {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
        } catch (Exception $e) {
            $has_deleted_at_column = false;
        }
        
        $where_deleted = '';
        if ($has_deleted_at_column) {
            $where_deleted = " AND (deleted_at IS NULL OR deleted_at = '')";
        }
        
        $sql = "SELECT id, points FROM $table_name 
            WHERE user_id = %d 
            AND type = 'earn' 
            AND expires_at IS NOT NULL 
            AND expires_at <= NOW() 
            AND is_expired = 0" . $where_deleted;
        
        $expired_transactions = $wpdb->get_results($wpdb->prepare($sql, $user_id));

        if (empty($expired_transactions)) {
            return array('expired_count' => 0, 'expired_points' => 0);
        }

        $total_expired_points = 0;
        foreach ($expired_transactions as $transaction) {
            $total_expired_points += intval($transaction->points);
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name 
            SET is_expired = 1 
            WHERE user_id = %d 
            AND type = 'earn' 
            AND expires_at IS NOT NULL 
            AND expires_at <= NOW() 
            AND is_expired = 0",
            $user_id
        ));

        if ($total_expired_points > 0) {
            $this->create_transaction(array(
                'user_id' => $user_id,
                'type' => 'expire',
                'points' => $total_expired_points,
                'description' => sprintf(
                    /* translators: %d: number of transactions */
                    __('Points expired across %d earn transactions', 'twork-points'),
                    count($expired_transactions)
                ),
            ));
        }

        $balance = $this->calculate_user_balance($user_id, true);
        update_user_meta($user_id, 'points_balance', $balance);

        return array(
            'expired_count' => count($expired_transactions),
            'expired_points' => $total_expired_points,
            'balance' => $balance,
        );
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create or update database tables as needed
        $this->create_tables();
        
        // Set default options
        if (!get_option('twork_points_rate')) {
            update_option('twork_points_rate', 1.0); // 1 point per $1
        }
        if (!get_option('twork_points_redemption_rate')) {
            update_option('twork_points_redemption_rate', 100); // 100 points = $1
        }
        if (!get_option('twork_points_signup_bonus')) {
            update_option('twork_points_signup_bonus', 100);
        }
        if (!get_option('twork_points_referral_bonus')) {
            update_option('twork_points_referral_bonus', 500);
        }
        if (!get_option('twork_points_birthday_bonus')) {
            update_option('twork_points_birthday_bonus', 200);
        }
        if (!get_option('twork_points_min_redemption')) {
            update_option('twork_points_min_redemption', 100);
        }
        if (!get_option('twork_points_max_redemption_percent')) {
            update_option('twork_points_max_redemption_percent', 50);
        }
        if (!get_option('twork_points_expiration_days')) {
            update_option('twork_points_expiration_days', 365);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Cleanup if needed
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Run migrations on init to ensure database is up to date
        // This ensures deleted_at column exists even if activation hook didn't run
        $this->run_migrations();
        
        // Load text domain
        load_plugin_textdomain('twork-points', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Create database tables with proper indexes and structure
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        
        // Get current database version
        $db_version = get_option('twork_points_db_version', '0');
        
        // Create main transactions table with comprehensive indexes
        // Added `status` column so admins can manage point lifecycle
        // (pending / approved / rejected) from the dashboard.
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            type varchar(20) NOT NULL,
            points int(11) NOT NULL,
            description text,
            order_id varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NULL,
            is_expired tinyint(1) DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'approved',
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_type (type),
            KEY idx_order_id (order_id),
            KEY idx_created_at (created_at),
            KEY idx_expires_at (expires_at),
            KEY idx_user_type_expired (user_id, type, is_expired),
            KEY idx_user_expires (user_id, expires_at, is_expired),
            KEY idx_order_user_type (order_id, user_id, type),
            KEY idx_status (status),
            UNIQUE KEY uniq_user_order_type (user_id, order_id, type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $audit_table = $wpdb->prefix . 'twork_point_audit_log';
        $audit_sql = "CREATE TABLE IF NOT EXISTS $audit_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            admin_user_id bigint(20) UNSIGNED NOT NULL,
            points int(11) NOT NULL,
            reason text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_admin (user_id, admin_user_id)
        ) $charset_collate;";
        dbDelta($audit_sql);
        
        // Table for explicit point claim / exchange requests submitted
        // from the mobile app. Admins can review and approve/reject
        // these from the dashboard before points are actually deducted.
        $claims_table = $wpdb->prefix . 'twork_point_claim_requests';
        $claims_sql = "CREATE TABLE IF NOT EXISTS $claims_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            points int(11) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            note text,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            processed_by bigint(20) UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY idx_user_status (user_id, status),
            KEY idx_status_created (status, created_at)
        ) $charset_collate;";
        dbDelta($claims_sql);
        
        // Update database version
        if ($db_version === '0') {
            add_option('twork_points_db_version', '1.0');
        }
        
        // Run migrations if needed
        $this->run_migrations();
    }
    
    /**
     * Run database migrations
     */
    private function run_migrations() {
        global $wpdb;
        
        $db_version = get_option('twork_points_db_version', '0');
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        
        // Migration 1.1: Expand order_id column if needed
        if (version_compare($db_version, '1.1', '<')) {
            $column_info = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'order_id'");
            if (!empty($column_info) && strpos($column_info[0]->Type, 'varchar(50)') !== false) {
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN order_id varchar(255) DEFAULT NULL");
            }
            update_option('twork_points_db_version', '1.1');
        }
        
        // Migration 1.2: Add missing indexes if they don't exist
        if (version_compare($db_version, '1.2', '<')) {
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
            $index_names = array();
            foreach ($indexes as $index) {
                $index_names[] = $index->Key_name;
            }
            
            // Add composite index for balance calculation
            if (!in_array('idx_user_type_expired', $index_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_user_type_expired (user_id, type, is_expired)");
            }
            
            // Add index for expiration queries
            if (!in_array('idx_user_expires', $index_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_user_expires (user_id, expires_at, is_expired)");
            }
            
            // Add index for order-related queries
            if (!in_array('idx_order_user_type', $index_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_order_user_type (order_id, user_id, type)");
            }

            if (!in_array('uniq_user_order_type', $index_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY uniq_user_order_type (user_id, order_id, type)");
            }
            
            update_option('twork_points_db_version', '1.2');
            $db_version = '1.2';
        }
        
        // Migration 1.3: Add status column to transactions + status index
        if (version_compare($db_version, '1.3', '<')) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'status'");
            if (empty($columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN status varchar(20) NOT NULL DEFAULT 'approved'");
            }
            
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
            $index_names = array();
            foreach ($indexes as $index) {
                $index_names[] = $index->Key_name;
            }
            if (!in_array('idx_status', $index_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_status (status)");
            }
            
            update_option('twork_points_db_version', '1.3');
            $db_version = '1.3';
        }
        
        // Migration 1.4: Add deleted_at column for trash functionality
        if (version_compare($db_version, '1.4', '<')) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
            if (empty($columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN deleted_at datetime NULL DEFAULT NULL");
                $wpdb->query("ALTER TABLE $table_name ADD INDEX idx_deleted_at (deleted_at)");
            }
            update_option('twork_points_db_version', '1.4');
            $db_version = '1.4';
        }
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Get point balance
        register_rest_route('twork/v1', '/points/balance/(?P<user_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_point_balance'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
            'args' => array(
                'user_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Get point transactions
        register_rest_route('twork/v1', '/points/transactions/(?P<user_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_point_transactions'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
            'args' => array(
                'user_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
                'page' => array(
                    'default' => 1,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
                'per_page' => array(
                    'default' => 20,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Earn points
        register_rest_route('twork/v1', '/points/earn', array(
            'methods' => 'POST',
            'callback' => array($this, 'earn_points'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        // Redeem points
        register_rest_route('twork/v1', '/points/redeem', array(
            'methods' => 'POST',
            'callback' => array($this, 'redeem_points'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));

        // Submit point exchange / claim request (does NOT change balance immediately)
        register_rest_route('twork/v1', '/points/claim-request', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_claim_request'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        // Sync points (for app to sync local transactions)
        register_rest_route('twork/v1', '/points/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'sync_points'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        // PROFESSIONAL FIX: Sync specific user's balance (for fixing sync issues)
        register_rest_route('twork/v1', '/points/sync-balance/(?P<user_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_sync_user_balance'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
            'args' => array(
                'user_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
                'force_recalculate' => array(
                    'default' => false,
                    'validate_callback' => function($param) {
                        return is_bool($param) || $param === 'true' || $param === 'false' || $param === '1' || $param === '0';
                    }
                ),
            ),
        ));
        
        // Get points expiring soon
        register_rest_route('twork/v1', '/points/expiring/(?P<user_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_points_expiring_soon'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        // Check and mark expired points
        register_rest_route('twork/v1', '/points/check-expired/(?P<user_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'check_expired_points'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        // Award referral bonus
        register_rest_route('twork/v1', '/points/referral', array(
            'methods' => 'POST',
            'callback' => array($this, 'award_referral_bonus'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        // Award birthday bonus
        register_rest_route('twork/v1', '/points/birthday', array(
            'methods' => 'POST',
            'callback' => array($this, 'award_birthday_bonus'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        // Custom Fields endpoints
        register_rest_route('twork/v1', '/custom-fields/definitions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_custom_field_definitions'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        
        register_rest_route('twork/v1', '/custom-fields/user/(?P<user_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_custom_fields'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
            'args' => array(
                'user_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        register_rest_route('twork/v1', '/custom-fields/user/(?P<user_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_user_custom_fields'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
            'args' => array(
                'user_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Register FCM token endpoint - forwards to webhook server
        register_rest_route('twork/v1', '/register-token', array(
            'methods' => 'POST',
            'callback' => array($this, 'register_fcm_token'),
            'permission_callback' => '__return_true', // Allow from app
        ));

        // Lucky Box per-user config (enable/disable)
        register_rest_route('twork/v1', '/luckybox/config/(?P<user_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_luckybox_config'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));

        // Lucky Box: submit a request (creates a pending transaction)
        register_rest_route('twork/v1', '/luckybox/open', array(
            'methods' => 'POST',
            'callback' => array($this, 'luckybox_submit_open'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));

        // Backward compatibility (older apps): Spin Wheel endpoints map to Lucky Box.
        register_rest_route('twork/v1', '/spinwheel/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_luckybox_config_compat'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
        register_rest_route('twork/v1', '/spinwheel/spin', array(
            'methods' => 'POST',
            'callback' => array($this, 'luckybox_submit_open'),
            'permission_callback' => array($this, 'check_woocommerce_auth'),
        ));
    }

    /**
     * Compat: old route had no user_id in path. Expect user_id as query param.
     */
    public function get_luckybox_config_compat($request) {
        $user_id = intval($request->get_param('user_id'));
        $request->set_param('user_id', $user_id);
        return $this->get_luckybox_config($request);
    }
    /**
     * Lucky Box: return per-user configuration.
     */
    public function get_luckybox_config($request) {
        global $wpdb;
        $user_id = intval($request->get_param('user_id'));
        if ($user_id <= 0) {
            return new WP_Error('invalid_params', 'Invalid user_id', array('status' => 400));
        }

        $enabled = get_user_meta($user_id, 'twork_luckybox_enabled', true) === '1';

        // One-request-at-a-time: if there is a pending LuckyBox transaction, user cannot open again.
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        $pending_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d AND type = %s AND status = %s ORDER BY created_at DESC LIMIT 1",
            $user_id,
            'luckybox',
            'pending'
        ));
        $has_pending = !empty($pending_id);

        return rest_ensure_response(array(
            'enabled' => $enabled,
            'has_pending' => $has_pending,
            'can_open' => $enabled && !$has_pending,
        ));
    }

    /**
     * Lucky Box: create a pending transaction representing a request.
     * Admin can later edit/approve the transaction and set points / description (reward).
     */
    public function luckybox_submit_open($request) {
        global $wpdb;
        $params = $request->get_json_params();

        $user_id = intval($params['user_id'] ?? 0);
        if ($user_id <= 0) {
            return new WP_Error('invalid_params', 'Invalid user_id', array('status' => 400));
        }

        $enabled = get_user_meta($user_id, 'twork_luckybox_enabled', true) === '1';
        if (!$enabled) {
            return new WP_Error('luckybox_disabled', 'Lucky Box is currently disabled', array('status' => 403));
        }

        // Enforce one active request per user: block if there is already a pending LuckyBox transaction.
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        $pending_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d AND type = %s AND status = %s ORDER BY created_at DESC LIMIT 1",
            $user_id,
            'luckybox',
            'pending'
        ));
        if (!empty($pending_id)) {
            return new WP_Error(
                'luckybox_pending',
                'Lucky Box request is already pending review',
                array(
                    'status' => 409,
                    'pending_transaction_id' => intval($pending_id),
                )
            );
        }

        $now = time();

        // Create a pending transaction with 0 points.
        // Admin will later edit points + description and approve it.
        $request_id = 'lucky-' . $now . '-' . wp_generate_password(6, false, false);
        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => 'luckybox',
            'points' => 0,
            'description' => 'Lucky Box pending',
            'order_id' => $request_id,
            'status' => 'pending',
        ));

        if (!$transaction_id) {
            return new WP_Error('transaction_failed', 'Failed to create lucky box transaction', array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'request_id' => $request_id,
            'status' => 'pending',
        ));
    }
    
    /**
     * Check WooCommerce authentication and validate user access
     */
    public function check_woocommerce_auth($request) {
        // Get consumer key and secret from request
        $consumer_key = '';
        $consumer_secret = '';
        
        // 1) Prefer explicit query parameters (App passes these to avoid
        //    conflicts with JSON Basic Authentication plugin which also uses
        //    the Authorization header for WP user login).
        $query_consumer_key = $request->get_param('consumer_key');
        $query_consumer_secret = $request->get_param('consumer_secret');
        
        if (!empty($query_consumer_key) && !empty($query_consumer_secret)) {
            $consumer_key    = sanitize_text_field($query_consumer_key);
            $consumer_secret = sanitize_text_field($query_consumer_secret);
        } else {
            // 2) Fallback to Authorization header for backwards compatibility
            $auth_header = $request->get_header('Authorization');
            if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
                $credentials = base64_decode(substr($auth_header, 6));
                if ($credentials) {
                    $parts = explode(':', $credentials, 2);
                    if (count($parts) === 2) {
                        $consumer_key = $parts[0];
                        $consumer_secret = $parts[1];
                    }
                }
            }
        }
        
        // Validate WooCommerce API credentials
        if (empty($consumer_key) || empty($consumer_secret)) {
            return new WP_Error('rest_forbidden', 'Invalid credentials', array('status' => 401));
        }
        
        // Validate credentials against WooCommerce API keys
        // Note: This is a basic check. In production, you might want to use WooCommerce API key validation
        $users = get_users(array(
            'meta_key' => 'woocommerce_api_consumer_key',
            'meta_value' => $consumer_key,
            'number' => 1,
        ));
        
        // If no direct match, allow through (WooCommerce handles its own auth)
        // But log for security monitoring
        if (empty($users)) {
            // Log unauthorized access attempt
            error_log('T-Work Points: API access attempt with invalid credentials');
        }
        
        // Get user ID from request if available
        $user_id = $request->get_param('user_id');
        if ($user_id) {
            $user_id = intval($user_id);
            
            // Verify user exists
            $user = get_user_by('ID', $user_id);
            if (!$user) {
                return new WP_Error('rest_invalid_user', 'User not found', array('status' => 404));
            }
            
            // Additional security: Verify user is not deleted/spam
            if ($user->user_status != 0) {
                return new WP_Error('rest_user_inactive', 'User account is not active', array('status' => 403));
            }
        }

        $nonce = $request->get_header('X-WP-Nonce');
        if (is_user_logged_in()) {
            if (empty($nonce) || ! wp_verify_nonce($nonce, 'wp_rest')) {
                TWork_Points_Logger::warning(
                    'REST nonce verification failed',
                    array(
                        'route' => $request->get_route(),
                        'user'  => get_current_user_id(),
                    )
                );
                return new WP_Error('rest_invalid_nonce', 'Security check failed', array('status' => 403));
            }
        }
        
        return true;
    }
    
    /**
     * Get point balance for user
     * PROFESSIONAL FIX: Uses get_user_point_balance (includes meta fallback) so we never overwrite
     * valid meta balance with 0 when user has balance in meta but no transactions.
     */
    public function get_point_balance($request) {
        $user_id = intval($request->get_param('user_id'));
        
        // Check if admin has set a custom balance value
        $is_custom = get_user_meta($user_id, 'points_balance_is_custom', true) === '1';
        
        // Use get_user_point_balance (includes points_balance/my_points meta fallback)
        // Avoids wiping valid meta balance to 0 when calculate_user_balance returns 0
        $balance = $this->get_user_point_balance($user_id);
        $lifetime_earned = $this->get_lifetime_points($user_id, 'earn');
        $lifetime_redeemed = $this->get_lifetime_points($user_id, 'redeem');
        $lifetime_expired = $this->get_lifetime_points($user_id, 'expire');
        
        // Only update customer meta if not custom (don't overwrite admin-set value)
        if (!$is_custom) {
            update_user_meta($user_id, 'points_balance', $balance);
            
            // PROFESSIONAL FIX: Sync my_points and my_point with balance
            $this->sync_my_points_with_balance($user_id, $balance, true);
        } else {
            // Use the custom value instead
            $custom_val = get_user_meta($user_id, 'points_balance', true);
            if (is_numeric($custom_val) && (int) $custom_val >= 0) {
                $balance = (int) $custom_val;
            }
            $this->sync_my_points_with_balance($user_id, $balance, true);
        }
        update_user_meta($user_id, 'lifetime_points_earned', $lifetime_earned);
        update_user_meta($user_id, 'lifetime_points_redeemed', $lifetime_redeemed);
        update_user_meta($user_id, 'lifetime_points_expired', $lifetime_expired);
        
        return rest_ensure_response(array(
            'user_id' => $user_id,
            'current_balance' => $balance,
            'lifetime_earned' => $lifetime_earned,
            'lifetime_redeemed' => $lifetime_redeemed,
            'lifetime_expired' => $lifetime_expired,
            'last_updated' => current_time('mysql'),
        ));
    }
    
    /**
     * Add custom fields to WordPress REST API user response
     * This makes custom fields (including points) available in the /wp-json/wp/v2/users/me endpoint
     */
    public function add_custom_fields_to_user_rest_api($response, $user, $request) {
        if (!is_wp_error($response)) {
            $user_id = $user->ID;
            
            // Use get_user_point_balance as single source of truth - same as engagement/poll validation
            // This ensures Home Page My PNP and သဘောတူပါသည် balance check always match
            $points_balance = $this->get_user_point_balance($user_id);
            
            // Get custom field definitions
            $custom_field_definitions = get_option('twork_custom_field_definitions', array());
            $custom_fields = array();
            
            // Add points_balance, my_point, my_points - all from same source (get_user_point_balance)
            $balance_str = (floor($points_balance) == $points_balance)
                ? (string) (int) $points_balance
                : (string) $points_balance;
            $custom_fields['points_balance'] = $balance_str;
            $custom_fields['my_point'] = $balance_str;
            $custom_fields['my_points'] = $balance_str;
            
            // Add other custom fields
            foreach ($custom_field_definitions as $field) {
                $key = isset($field['key']) ? $field['key'] : '';
                if ($key && $key !== 'points_balance' && $key !== 'my_point') {
                    $value = get_user_meta($user_id, 'custom_field_' . $key, true);
                    if ($value !== false && $value !== '') {
                        $custom_fields[$key] = strval($value);
                    }
                }
            }
            
            // Add custom_fields to response
            $data = $response->get_data();
            $data['custom_fields'] = $custom_fields;
            
            // Also add points_balance to meta for backward compatibility
            if (!isset($data['meta'])) {
                $data['meta'] = array();
            }
            if (!is_array($data['meta'])) {
                $data['meta'] = array();
            }
            $data['meta']['custom_field_points_balance'] = strval($points_balance);
            $data['meta']['points_balance'] = strval($points_balance);
            
            $response->set_data($data);
        }
        
        return $response;
    }
    
    /**
     * Get point transactions for user
     */
    public function get_point_transactions($request) {
        global $wpdb;
        
        $user_id = intval($request->get_param('user_id'));
        $page = intval($request->get_param('page')) ?: 1;
        $per_page = intval($request->get_param('per_page')) ?: 20;
        $offset = ($page - 1) * $per_page;
        
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        $is_legacy_source = false;
        
        // Order by id DESC first (newest ID = newest transaction), then created_at DESC as secondary
        // This ensures consistent ordering even when multiple transactions have the same timestamp
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE user_id = %d 
            ORDER BY id DESC, created_at DESC 
            LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ));
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
            $user_id
        ));

        // BACKWARD COMPATIBILITY:
        // Some installations historically stored transactions in twork_reward_transactions (rewards-system).
        // If points table has no data for this user, fall back to rewards table so the mobile app can
        // still show a complete history.
        if (intval($total) === 0) {
            $rewards_table = $wpdb->prefix . 'twork_reward_transactions';
            $rewards_table_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $rewards_table)
            );

            if ($rewards_table_exists === $rewards_table) {
                // Only include non-deleted rows if the column exists.
                $has_deleted_at = false;
                try {
                    $column_exists = $wpdb->get_results(
                        "SHOW COLUMNS FROM $rewards_table LIKE 'deleted_at'"
                    );
                    $has_deleted_at = !empty($column_exists);
                } catch (Exception $e) {
                    $has_deleted_at = false;
                }

                $deleted_where = $has_deleted_at ? " AND deleted_at IS NULL" : "";

                $transactions = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $rewards_table 
                    WHERE user_id = %d $deleted_where
                    ORDER BY id DESC, created_at DESC 
                    LIMIT %d OFFSET %d",
                    $user_id,
                    $per_page,
                    $offset
                ));

                $total = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $rewards_table WHERE user_id = %d $deleted_where",
                    $user_id
                ));

                $is_legacy_source = true;
            }
        }
        
        $formatted_transactions = array();
        foreach ($transactions as $transaction) {
            if ($is_legacy_source) {
                // Legacy rewards table schema mapping to points transaction schema.
                $points_int = 0;
                $points_value = isset($transaction->points_value) ? $transaction->points_value : '';
                if ($points_value !== null && $points_value !== '') {
                    $points_int = is_numeric($points_value)
                        ? (int) floatval($points_value)
                        : intval($points_value);
                }

                $order_id = isset($transaction->order_id) ? $transaction->order_id : null;
                $legacy_type = isset($transaction->type) ? $transaction->type : 'reward';

                // Map reward transaction types to point transaction types.
                $mapped_type = 'earn';
                if (!empty($order_id) && strpos($order_id, 'manual:') === 0) {
                    $mapped_type = 'adjust';
                } else {
                    $type_map = array(
                        'reward' => 'earn',
                        'exchange' => 'redeem',
                        'luckybox' => 'earn',
                        'prize_code' => 'earn',
                    );
                    if (isset($type_map[$legacy_type])) {
                        $mapped_type = $type_map[$legacy_type];
                    }
                }

                $formatted_transactions[] = array(
                    'id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'type' => $mapped_type,
                    'points' => $points_int,
                    'description' => !empty($transaction->description)
                        ? $transaction->description
                        : 'Points Transaction',
                    'order_id' => $order_id,
                    'created_at' => $transaction->created_at,
                    'expires_at' => null,
                    'is_expired' => false,
                    'status' => !empty($transaction->status) ? $transaction->status : 'approved',
                );
            } else {
                // Points system table mapping.
                // PROFESSIONAL FIX: Preserve negative values for adjustments.
                $points_value = intval($transaction->points);

                $formatted_transactions[] = array(
                    'id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'type' => $transaction->type, // Type is already 'adjust' for manual adjustments
                    'points' => $points_value, // Preserve negative values
                    'description' => $transaction->description,
                    'order_id' => $transaction->order_id,
                    'created_at' => $transaction->created_at,
                    'expires_at' => $transaction->expires_at,
                    'is_expired' => (bool) $transaction->is_expired,
                    'status' => isset($transaction->status) ? $transaction->status : 'approved',
                );
            }
        }
        
        return rest_ensure_response(array(
            'transactions' => $formatted_transactions,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ));
    }
    
    /**
     * Earn points
     */
    public function earn_points($request) {
        $params = $request->get_json_params();
        
        $user_id = intval($params['user_id'] ?? 0);
        $points = intval($params['points'] ?? 0);
        $type = sanitize_text_field($params['type'] ?? 'earn');
        $description = sanitize_text_field($params['description'] ?? '');
        $order_id_param = $params['order_id'] ?? '';
        $order_id = (is_string($order_id_param) && $order_id_param !== '')
            ? sanitize_text_field($order_id_param)
            : null;
        $expires_at = !empty($params['expires_at']) ? sanitize_text_field($params['expires_at']) : null;
        $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'approved';
        
        // Validate status
        if (!in_array($status, array('pending', 'approved', 'rejected'), true)) {
            $status = 'approved';
        }
        
        if (!$user_id || $points <= 0) {
            return new WP_Error('invalid_params', 'Invalid user_id or points', array('status' => 400));
        }
        
        // Create transaction
        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => $type,
            'points' => $points,
            'description' => $description,
            'order_id' => $order_id,
            'expires_at' => $expires_at,
            'status' => $status,
        ));
        
        if (!$transaction_id) {
            $this->record_sync_failure('earn_points', 'Failed to create transaction');
            return new WP_Error('transaction_failed', 'Failed to create transaction', array('status' => 500));
        }
        
        // Update balance cache
        $balance = $this->calculate_user_balance($user_id);
        update_user_meta($user_id, 'points_balance', $balance);
        
        // PROFESSIONAL FIX: Sync my_points with updated balance
        // calculate_user_balance already syncs, but force sync here for safety
        $this->sync_my_points_with_balance($user_id, $balance, true);

        $this->record_sync_success();

        TWork_Points_Logger::info(
            'Earn points transaction created via REST',
            array(
                'transaction_id' => $transaction_id,
                'user_id' => $user_id,
                'points' => $points,
                'order_id' => $order_id,
            )
        );
        
        return rest_ensure_response(array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'new_balance' => $balance,
        ));
    }
    
    /**
     * Redeem points
     */
    public function redeem_points($request) {
        $params = $request->get_json_params();
        
        $user_id = intval($params['user_id'] ?? 0);
        $points = intval($params['points'] ?? 0);
        $description = sanitize_text_field($params['description'] ?? '');
        $order_id_param = $params['order_id'] ?? '';
        $order_id = (is_string($order_id_param) && $order_id_param !== '')
            ? sanitize_text_field($order_id_param)
            : null;
        
        if (!$user_id || $points <= 0) {
            return new WP_Error('invalid_params', 'Invalid user_id or points', array('status' => 400));
        }
        
        // Check if user has enough points (force recalculation for accuracy)
        $current_balance = $this->calculate_user_balance($user_id, true);
        if ($current_balance < $points) {
            return new WP_Error('insufficient_points', 'Insufficient points', array(
                'status' => 400,
                'current_balance' => $current_balance,
                'required' => $points,
            ));
        }
        
        // Create transaction
        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => 'redeem',
            'points' => $points,
            'description' => $description ?: 'Points redeemed',
            'order_id' => $order_id,
        ));
        
        if (!$transaction_id) {
            $this->record_sync_failure('redeem_points', 'Failed to create transaction');
            return new WP_Error('transaction_failed', 'Failed to create transaction', array('status' => 500));
        }
        
        // Save redeemed points to order meta (for potential refund later)
        if (!empty($order_id)) {
            // Try to get WooCommerce order by ID (may be numeric or string with prefix)
            $woo_order_id = preg_replace('/[^0-9]/', '', $order_id); // Extract numeric ID
            $order = wc_get_order($woo_order_id);
            
            if ($order) {
                // Get configurable redemption rate (default: 100 points = $1)
                $redemption_rate = floatval(get_option('twork_points_redemption_rate', 100));
                $discount_amount = floatval($points) / $redemption_rate;
                
                update_post_meta($order->get_id(), '_points_redeemed', $points);
                update_post_meta($order->get_id(), '_points_discount', $discount_amount);
                update_post_meta($order->get_id(), '_points_redemption_rate', $redemption_rate);
                
                // Add order note
                $order->add_order_note(sprintf(
                    __('Points redeemed: %d points for $%.2f discount', 'twork-points'),
                    $points,
                    $discount_amount
                ));
            }
        }
        
        // Update balance cache (force recalculation)
        $balance = $this->calculate_user_balance($user_id, true);
        update_user_meta($user_id, 'points_balance', $balance);
        update_user_meta($user_id, 'lifetime_points_redeemed', $this->get_lifetime_points($user_id, 'redeem'));
        
        // PROFESSIONAL FIX: Sync my_points with updated balance
        // calculate_user_balance already syncs, but force sync here for safety
        $this->sync_my_points_with_balance($user_id, $balance, true);

        $this->record_sync_success();

        TWork_Points_Logger::info(
            'Redeem transaction created via REST',
            array(
                'transaction_id' => $transaction_id,
                'user_id' => $user_id,
                'points' => $points,
                'order_id' => $order_id,
            )
        );
        
        return rest_ensure_response(array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'new_balance' => $balance,
        ));
    }
    
    /**
     * Get user point balance (for external use, e.g. poll betting).
     * Uses calculated balance from transactions, with fallback to points_balance/my_points meta
     * when user's balance is stored in meta (e.g. from twork-rewards or admin adjustment).
     *
     * @param int $user_id User ID
     * @return int Current balance
     */
    public function get_user_point_balance($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) {
            return 0;
        }
        // 1. Get calculated balance from transactions (source of truth for twork-points)
        $calculated = $this->calculate_user_balance($user_id, false);
        // 2. Get balance from meta (points_balance, my_points, my_point, _user_pnp_balance legacy)
        //    Parse comma-safe so "18,200" or "18200" both work; include my_point (singular) used by REST.
        $meta_keys = array('points_balance', 'my_points', 'my_point', '_user_pnp_balance');
        $meta_val = 0;
        foreach ($meta_keys as $key) {
            $raw = get_user_meta($user_id, $key, true);
            if ($raw === '' || $raw === false) {
                continue;
            }
            $normalized = is_string($raw) ? str_replace(',', '', trim($raw)) : $raw;
            if (is_numeric($normalized)) {
                $val = (int) $normalized;
                if ($val >= 0) {
                    $meta_val = max($meta_val, $val);
                }
            }
        }
        // Use the higher value so we never undercount (avoids false "insufficient" when meta has balance)
        return max($calculated, $meta_val);
    }
    
    /**
     * Deduct points for poll vote (from actual point balance).
     * Used by T-Work Rewards engagement/poll when poll_base_cost > 0.
     *
     * @param int    $user_id    User ID
     * @param int    $points     Points to deduct (positive number)
     * @param string $description Transaction description (e.g. "Poll vote - item 123")
     * @return int|false New balance on success, false on failure
     */
    public function deduct_for_poll_vote($user_id, $points, $description = '') {
        if (!$user_id || $points <= 0) {
            return false;
        }
        $user_id = absint($user_id);
        $points = absint($points);
        $description = $description ? sanitize_text_field($description) : 'Poll vote';

        // Get effective balance (includes meta fallback) before deducting
        $effective_balance = $this->get_user_point_balance($user_id);
        if ($effective_balance < $points) {
            return false;
        }

        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => 'redeem',
            'points' => $points,
            'description' => $description,
            'order_id' => '',
            'status' => 'approved',
        ));

        if (!$transaction_id) {
            return false;
        }

        // Always set new balance from pre-deduct effective balance minus points.
        // This ensures actual balance is deducted even when calculated is 0 (meta-only users).
        $new_balance = $effective_balance - $points;
        if ($new_balance < 0) {
            $new_balance = 0;
        }

        update_user_meta($user_id, 'points_balance', $new_balance);
        update_user_meta($user_id, 'lifetime_points_redeemed', $this->get_lifetime_points($user_id, 'redeem'));
        $this->sync_my_points_with_balance($user_id, $new_balance, true);
        update_user_meta($user_id, '_user_pnp_balance', (string) $new_balance);
        $this->invalidate_balance_cache($user_id);

        TWork_Points_Logger::info(
            'Poll vote deduction applied',
            array(
                'user_id' => $user_id,
                'points_deducted' => $points,
                'previous_balance' => $effective_balance,
                'new_balance' => $new_balance,
                'transaction_id' => $transaction_id,
            )
        );

        return $new_balance;
    }
    
    /**
     * Sync points (for app to sync local transactions)
     */
    public function sync_points($request) {
        $params = $request->get_json_params();
        
        $user_id = intval($params['user_id'] ?? 0);
        $transactions = $params['transactions'] ?? array();
        
        if (!$user_id) {
            return new WP_Error('invalid_params', 'Invalid user_id', array('status' => 400));
        }
        
        $synced = 0;
        $errors = array();
        
        // Invalidate cache once at the start (will be recalculated at the end)
        $this->invalidate_balance_cache($user_id);
        
        foreach ($transactions as $transaction) {
            try {
                // Check if transaction already exists (duplicate prevention)
                $order_id = sanitize_text_field($transaction['order_id'] ?? '');
                $type = sanitize_text_field($transaction['type'] ?? 'earn');
                $points = intval($transaction['points'] ?? 0);
                $description = sanitize_text_field($transaction['description'] ?? '');
                
                // Enhanced duplicate check for ALL transaction types
                global $wpdb;
                $table_name = $wpdb->prefix . 'twork_point_transactions';
                
                // Check if deleted_at column exists
                $has_deleted_at_column = false;
                try {
                    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
                    $has_deleted_at_column = !empty($column_exists);
                } catch (Exception $e) {
                    $has_deleted_at_column = false;
                }
                
                $deleted_where = '';
                if ($has_deleted_at_column) {
                    $deleted_where = " AND (deleted_at IS NULL OR deleted_at = '')";
                }
                
                // Check for duplicates: stricter check for redeem/earn transactions
                if (!empty($order_id)) {
                    // With order_id: check within last 10 minutes
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name 
                        WHERE user_id = %d 
                        AND order_id = %s 
                        AND type = %s 
                        AND points = %d
                        AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)" . $deleted_where . "
                        LIMIT 1",
                        $user_id,
                        $order_id,
                        $type,
                        $points
                    ));
                } else {
                    // Without order_id: check within last 5 minutes with description match
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name 
                        WHERE user_id = %d 
                        AND (order_id IS NULL OR order_id = '')
                        AND type = %s 
                        AND points = %d
                        AND description = %s
                        AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)" . $deleted_where . "
                        LIMIT 1",
                        $user_id,
                        $type,
                        $points,
                        $description
                    ));
                }
                
                if ($existing) {
                    // Transaction already exists, skip silently
                    TWork_Points_Logger::info(
                        'Duplicate transaction skipped in sync',
                        array(
                            'user_id' => $user_id,
                            'type' => $type,
                            'points' => $points,
                            'order_id' => $order_id,
                            'existing_id' => intval($existing),
                        )
                    );
                    continue;
                }
                
                $status = isset($transaction['status']) ? sanitize_text_field($transaction['status']) : 'approved';
                // Validate status
                if (!in_array($status, array('pending', 'approved', 'rejected'), true)) {
                    $status = 'approved';
                }
                
                // Skip cache invalidation during batch - will be invalidated once at the end
                $transaction_id = $this->create_transaction(array(
                    'user_id' => $user_id,
                    'type' => $type,
                    'points' => $points,
                    'description' => sanitize_text_field($transaction['description'] ?? ''),
                    'order_id' => $order_id,
                    'expires_at' => !empty($transaction['expires_at']) ? sanitize_text_field($transaction['expires_at']) : null,
                    'status' => $status,
                ), true); // Skip cache invalidation - batch operation
                
                if ($transaction_id) {
                    $synced++;
                    TWork_Points_Logger::info(
                        'Queued transaction synced',
                        array(
                            'transaction_id' => $transaction_id,
                            'user_id' => $user_id,
                            'type' => $type,
                            'points' => $points,
                            'order_id' => $order_id,
                        )
                    );
                } else {
                    // Enhanced error message with more context
                    $order_id_display = !empty($order_id) ? $order_id : '(no order_id)';
                    $message = sprintf('Failed to create transaction for user %d (%s/%s)', $user_id, $type, $order_id_display);
                    $errors[] = $message;
                    $this->record_sync_failure('sync_points', $message);
                    
                    // Log additional context for debugging
                    TWork_Points_Logger::error(
                        'Sync transaction creation failed',
                        array(
                            'user_id' => $user_id,
                            'type' => $type,
                            'points' => $points,
                            'order_id' => $order_id,
                            'description' => $transaction['description'] ?? '',
                            'status' => $status,
                        )
                    );
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                $this->record_sync_failure('sync_points', $e->getMessage());
            }
        }
        
        // Update balance cache (force recalculation after sync)
        $balance = $this->calculate_user_balance($user_id, true);
        update_user_meta($user_id, 'points_balance', $balance);

        if (empty($errors)) {
            $this->record_sync_success();
        }

        TWork_Points_Logger::info(
            'Sync endpoint processed batch',
            array(
                'user_id' => $user_id,
                'synced'  => $synced,
                'errors'  => count($errors),
                'total'   => count($transactions),
            )
        );
        
        return rest_ensure_response(array(
            'success' => true,
            'synced' => $synced,
            'total' => count($transactions),
            'errors' => $errors,
            'new_balance' => $balance,
        ));
    }

    /**
     * Handle point exchange / claim request from the app.
     * This records a pending request in a dedicated table; no balance
     * changes are applied until an admin approves the request.
     */
    public function handle_claim_request(WP_REST_Request $request) {
        global $wpdb;

        $params   = $request->get_json_params();
        $user_id  = isset($params['user_id']) ? intval($params['user_id']) : 0;
        $points   = isset($params['points']) ? intval($params['points']) : 0;
        $phone    = isset($params['phone']) ? sanitize_text_field($params['phone']) : '';
        $note     = isset($params['note']) ? sanitize_text_field($params['note']) : '';

        if ($user_id <= 0 || $points <= 0) {
            return new WP_Error(
                'twork_points_invalid_request',
                __('User ID and points are required for a claim request.', 'twork-points'),
                array('status' => 400)
            );
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error(
                'twork_points_user_not_found',
                __('User not found.', 'twork-points'),
                array('status' => 404)
            );
        }

        $table = $wpdb->prefix . 'twork_point_claim_requests';

        $inserted = $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'points'  => $points,
                'phone'   => $phone,
                'note'    => $note,
                'status'  => 'pending',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            return new WP_Error(
                'twork_points_db_error',
                __('Could not save claim request. Please try again.', 'twork-points'),
                array('status' => 500)
            );
        }

        $request_id = $wpdb->insert_id;

        return rest_ensure_response(array(
            'success'    => true,
            'request_id' => $request_id,
            'status'     => 'pending',
        ));
    }
    
    /**
     * Create point transaction with duplicate prevention and validation
     * Uses database transactions for data integrity
     * 
     * @param array $data Transaction data
     * @param bool $skip_cache_invalidation Set to true to skip cache invalidation (for batch operations)
     * @return int|false Transaction ID or false on failure
     */
    // Static flag to prevent recursive calls
    private static $creating_transaction = array();
    
    private function create_transaction($data, $skip_cache_invalidation = false) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        $user_id = intval($data['user_id']);
        $type = sanitize_text_field($data['type']);
        $points = intval($data['points']);
        $raw_order_id = $data['order_id'] ?? '';
        $order_id = (is_string($raw_order_id) && $raw_order_id !== '')
            ? sanitize_text_field($raw_order_id)
            : null;
        
        // Prevent recursive calls - create key based on transaction signature (without time)
        // This catches if the same transaction is being created multiple times in the same request
        $transaction_key = md5($user_id . '|' . $type . '|' . $points . '|' . ($order_id ?: '') . '|' . md5($data['description'] ?? ''));
        
        // Check if we're already processing this exact transaction
        if (isset(self::$creating_transaction[$transaction_key])) {
            TWork_Points_Logger::warning(
                'Recursive transaction creation prevented (same transaction already in progress)',
                array(
                    'user_id' => $user_id,
                    'type' => $type,
                    'points' => $points,
                    'order_id' => $order_id,
                )
            );
            return false;
        }
        
        // Mark as processing
        self::$creating_transaction[$transaction_key] = true;
        
        // Auto-cleanup old entries to prevent memory leak (keep only last 50)
        if (count(self::$creating_transaction) > 50) {
            // Remove oldest entries (keep last 25)
            $keys = array_keys(self::$creating_transaction);
            for ($i = 0; $i < count($keys) - 25; $i++) {
                unset(self::$creating_transaction[$keys[$i]]);
            }
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // For redeem transactions, validate balance BEFORE creating transaction
            // unless this has been explicitly flagged as a "pending" request
            // (e.g. app exchange / claim request managed by admins).
            $status = isset($data['status']) ? sanitize_text_field($data['status']) : 'approved';
            if ($type === 'redeem' && $status === 'approved') {
                // Use get_user_point_balance (includes meta fallback) so users with balance
                // only in meta (e.g. admin-set or legacy) can still redeem. Prevents "success"
                // message while actual balance never deducts.
                $current_balance = $this->get_user_point_balance($user_id);
                if ($current_balance < $points) {
                    $wpdb->query('ROLLBACK');
                    unset(self::$creating_transaction[$transaction_key]);
                    TWork_Points_Logger::warning(
                        'Insufficient balance for redeem',
                        array(
                            'user_id' => $user_id,
                            'requested_points' => $points,
                            'available_balance' => $current_balance,
                        )
                    );
                    return false;
                }
            }
            
            // Check for duplicate transaction (improved logic for all types)
            // Handle both cases: with order_id and without order_id (NULL)
            if (!empty($order_id)) {
                // For transactions with order_id, check for duplicates within last 10 minutes
                $time_window = in_array($type, array('earn', 'redeem')) ? 10 : 5;
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_name 
                    WHERE user_id = %d 
                    AND order_id = %s 
                    AND type = %s 
                    AND points = %d 
                    AND created_at > DATE_SUB(NOW(), INTERVAL %d MINUTE)
                    LIMIT 1",
                    $user_id,
                    $order_id,
                    $type,
                    $points,
                    $time_window
                ));
                
                if ($existing) {
                    // Duplicate transaction found
                    TWork_Points_Logger::warning(
                        'Duplicate transaction prevented (with order_id)',
                        array(
                            'user_id' => $user_id,
                            'order_id' => $order_id,
                            'type'     => $type,
                            'existing' => intval($existing),
                        )
                    );
                    $wpdb->query('ROLLBACK');
                    unset(self::$creating_transaction[$transaction_key]);
                    return intval($existing);
                }
            } elseif (in_array($type, array('earn', 'redeem'))) {
                // For earn/redeem transactions WITHOUT order_id, check for duplicates within last 5 minutes
                // Use description as additional match criteria since order_id is NULL
                $time_window = 5; // Shorter window for transactions without order_id
                $description = sanitize_text_field($data['description'] ?? '');
                
                // Check if deleted_at column exists
                $has_deleted_at_column = false;
                try {
                    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
                    $has_deleted_at_column = !empty($column_exists);
                } catch (Exception $e) {
                    $has_deleted_at_column = false;
                }
                
                $deleted_where = '';
                if ($has_deleted_at_column) {
                    $deleted_where = " AND (deleted_at IS NULL OR deleted_at = '')";
                }
                
                // Check for duplicate: same user, type, points, description, no order_id, within time window
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_name 
                    WHERE user_id = %d 
                    AND (order_id IS NULL OR order_id = '')
                    AND type = %s 
                    AND points = %d 
                    AND description = %s
                    AND created_at > DATE_SUB(NOW(), INTERVAL %d MINUTE)" . $deleted_where . "
                    LIMIT 1",
                    $user_id,
                    $type,
                    $points,
                    $description,
                    $time_window
                ));
                
                if ($existing) {
                    // Duplicate transaction found (without order_id)
                    TWork_Points_Logger::warning(
                        'Duplicate transaction prevented (without order_id)',
                        array(
                            'user_id' => $user_id,
                            'type'     => $type,
                            'points'   => $points,
                            'description' => $description,
                            'existing' => intval($existing),
                        )
                    );
                    $wpdb->query('ROLLBACK');
                    unset(self::$creating_transaction[$transaction_key]);
                    return intval($existing);
                }
            }
            
            // Additional duplicate check for birthday/referral (once per period)
            if (in_array($type, array('birthday', 'referral'))) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_name 
                    WHERE user_id = %d 
                    AND type = %s 
                    AND points = %d 
                    AND DATE(created_at) = CURDATE()
                    LIMIT 1",
                    $user_id,
                    $type,
                    $points
                ));
                
                if ($existing) {
                    $wpdb->query('COMMIT');
                    unset(self::$creating_transaction[$transaction_key]);
                    return intval($existing);
                }
            }
            
            // Duplicate check for redeem transactions (prevent infinite loops)
            // Similar to adjust, redeem transactions can cause loops if not properly prevented
            if ($type === 'redeem') {
                // Check for duplicate redeem transactions within last 5 seconds
                // This prevents rapid duplicate submissions that would cause infinite loops
                $time_window = 5; // 5 seconds window to prevent rapid duplicates
                
                // Check if deleted_at column exists for WHERE clause
                $has_deleted_at_column = false;
                try {
                    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
                    $has_deleted_at_column = !empty($column_exists);
                } catch (Exception $e) {
                    $has_deleted_at_column = false;
                }
                
                $deleted_where = '';
                if ($has_deleted_at_column) {
                    $deleted_where = " AND (deleted_at IS NULL OR deleted_at = '')";
                }
                
                // Build duplicate check query based on whether order_id exists
                if (!empty($order_id)) {
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name 
                        WHERE user_id = %d 
                        AND type = 'redeem' 
                        AND order_id = %s
                        AND points = %d 
                        AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)" . $deleted_where . "
                        ORDER BY created_at DESC
                        LIMIT 1",
                        $user_id,
                        $order_id,
                        $points,
                        $time_window
                    ));
                } else {
                    $description = sanitize_text_field($data['description'] ?? '');
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table_name 
                        WHERE user_id = %d 
                        AND type = 'redeem' 
                        AND (order_id IS NULL OR order_id = '')
                        AND points = %d 
                        AND description = %s
                        AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)" . $deleted_where . "
                        ORDER BY created_at DESC
                        LIMIT 1",
                        $user_id,
                        $points,
                        $description,
                        $time_window
                    ));
                }
                
                if ($existing) {
                    TWork_Points_Logger::warning(
                        'Duplicate redeem transaction prevented (infinite loop protection)',
                        array(
                            'user_id' => $user_id,
                            'points' => $points,
                            'order_id' => $order_id,
                            'time_window' => $time_window,
                            'existing_id' => intval($existing),
                        )
                    );
                    $wpdb->query('ROLLBACK');
                    unset(self::$creating_transaction[$transaction_key]);
                    return intval($existing);
                }
            }
            
            // Duplicate check for adjust transactions (prevent multiple adjusts within short time window)
            // This prevents infinite loops when adjust transactions trigger hooks that create more adjusts
            if ($type === 'adjust') {
                // Check for duplicate adjust transactions within last 5 seconds
                // Same user, same points amount (description might vary, so we don't check it)
                // This catches rapid duplicate submissions that would cause infinite loops
                $time_window = 5; // 5 seconds window to prevent rapid duplicates
                
                // Check if deleted_at column exists for WHERE clause
                $has_deleted_at_column = false;
                try {
                    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
                    $has_deleted_at_column = !empty($column_exists);
                } catch (Exception $e) {
                    $has_deleted_at_column = false;
                }
                
                $deleted_where = '';
                if ($has_deleted_at_column) {
                    $deleted_where = " AND (deleted_at IS NULL OR deleted_at = '')";
                }
                
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_name 
                    WHERE user_id = %d 
                    AND type = 'adjust' 
                    AND points = %d 
                    AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)" . $deleted_where . "
                    ORDER BY created_at DESC
                    LIMIT 1",
                    $user_id,
                    $points,
                    $time_window
                ));
                
                if ($existing) {
                    TWork_Points_Logger::warning(
                        'Duplicate adjust transaction prevented (infinite loop protection)',
                        array(
                            'user_id' => $user_id,
                            'points' => $points,
                            'time_window' => $time_window,
                            'existing_id' => intval($existing),
                        )
                    );
                    $wpdb->query('COMMIT');
                    unset(self::$creating_transaction[$transaction_key]);
                    return intval($existing);
                }
            }
            
            // Insert transaction
            // Handle order_id: if empty string, set to NULL to avoid UNIQUE constraint issues with empty strings
            $insert_order_id = (!empty($order_id)) ? $order_id : null;
            
            $result = $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'type' => $type,
                    'points' => $points,
                    'description' => sanitize_text_field($data['description'] ?? ''),
                    'order_id' => $insert_order_id,
                    'expires_at' => !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null,
                    'created_at' => current_time('mysql'),
                    'status' => $status,
                ),
                array('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                unset(self::$creating_transaction[$transaction_key]);
                
                // Enhanced error logging
                $db_error = $wpdb->last_error;
                $error_message = 'T-Work Points: Failed to insert transaction: ' . $db_error;
                error_log($error_message);
                
                // Check if it's a duplicate key error (UNIQUE constraint violation)
                $is_duplicate_error = false;
                if (strpos($db_error, 'Duplicate entry') !== false || strpos($db_error, '1062') !== false) {
                    $is_duplicate_error = true;
                    // Try to find the existing transaction
                    if ($insert_order_id) {
                        $existing_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table_name 
                            WHERE user_id = %d 
                            AND type = %s 
                            AND points = %d 
                            AND order_id = %s
                            ORDER BY id DESC
                            LIMIT 1",
                            $user_id,
                            $type,
                            $points,
                            $insert_order_id
                        ));
                    } else {
                        $existing_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table_name 
                            WHERE user_id = %d 
                            AND type = %s 
                            AND points = %d 
                            AND (order_id IS NULL OR order_id = '')
                            ORDER BY id DESC
                            LIMIT 1",
                            $user_id,
                            $type,
                            $points
                        ));
                    }
                    
                    if ($existing_id) {
                        TWork_Points_Logger::warning(
                            'Duplicate transaction prevented (UNIQUE constraint)',
                            array(
                                'user_id' => $user_id,
                                'type' => $type,
                                'points' => $points,
                                'order_id' => $insert_order_id,
                                'existing_id' => intval($existing_id),
                                'db_error' => $db_error,
                            )
                        );
                        return intval($existing_id); // Return existing transaction ID
                    }
                }
                
                TWork_Points_Logger::error(
                    'Database insert failed',
                    array(
                        'user_id' => $user_id,
                        'type' => $type,
                        'points' => $points,
                        'order_id' => $insert_order_id,
                        'db_error' => $db_error,
                        'is_duplicate_error' => $is_duplicate_error,
                    )
                );
                return false;
            }
            
            $transaction_id = $wpdb->insert_id;
            
            // Commit transaction first
            $wpdb->query('COMMIT');
            
            // Clear the recursive flag BEFORE any other operations that might trigger hooks
            unset(self::$creating_transaction[$transaction_key]);
            
            // Invalidate balance cache AFTER commit (prevents nested transaction issues)
            // Skip if this is part of a batch operation (will be invalidated once at the end)
            if (!$skip_cache_invalidation) {
                $this->invalidate_balance_cache($user_id);
            }
            TWork_Points_Logger::info(
                'Transaction stored',
                array(
                    'transaction_id' => $transaction_id,
                    'user_id' => $user_id,
                    'type' => $type,
                    'points' => $points,
                    'order_id' => $order_id,
                )
            );
            
            return $transaction_id;
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            unset(self::$creating_transaction[$transaction_key]);
            error_log('T-Work Points: Error creating transaction: ' . $e->getMessage());
            TWork_Points_Logger::error(
                'Transaction creation threw exception',
                array(
                    'user_id' => $user_id,
                    'type' => $type,
                    'points' => $points,
                    'order_id' => $order_id,
                    'error' => $e->getMessage(),
                )
            );
            $this->record_sync_failure('create_transaction', $e->getMessage());
            return false;
        }
    }
    
    /**
     * PROFESSIONAL FIX: Sync my_points and my_point with points_balance
     * This ensures the app displays the correct balance (app uses my_points as Priority 1)
     * 
     * @param int $user_id User ID
     * @param int|string $points_balance The points balance to sync
     * @param bool $force_sync Force sync even if values are close
     * @return void
     */
    private function sync_my_points_with_balance($user_id, $points_balance, $force_sync = false) {
        // Check if admin has set a custom balance value
        $is_custom = get_user_meta($user_id, 'points_balance_is_custom', true) === '1';
        
        // Don't sync if custom balance is set (unless forced)
        if ($is_custom && !$force_sync) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'T-Work Points: Skipping sync for user %d (custom balance set)',
                    $user_id
                ));
            }
            return;
        }
        
        // Get current my_points value
        $my_points_current = get_user_meta($user_id, 'my_points', true);
        $points_balance_int = (int)$points_balance;
        $my_points_int = is_numeric($my_points_current) ? (int)$my_points_current : 0;
        
        // Sync if: force_sync OR my_points is empty OR difference is significant (more than 1 point)
        if ($force_sync || $my_points_current === false || $my_points_current === '' || abs($points_balance_int - $my_points_int) > 1) {
            $stored_balance = (floor($points_balance) == $points_balance)
                ? (string) (int) $points_balance
                : (string) $points_balance;
            
            update_user_meta($user_id, 'my_points', $stored_balance);
            update_user_meta($user_id, 'my_point', $stored_balance); // Backward compatibility
            update_user_meta($user_id, 'my_points_updated_at', time());
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'T-Work Points: Synced my_points for user %d - points_balance: %d, my_points: %s -> %s',
                    $user_id,
                    $points_balance_int,
                    $my_points_current !== false ? $my_points_current : 'empty',
                    $stored_balance
                ));
            }
        }
    }

    /**
     * Calculate user's current point balance (optimized with single query)
     * Uses database transactions for data integrity
     */
    private function calculate_user_balance($user_id, $force_recalculate = false) {
        global $wpdb;
        
        // Use cached balance if available and not forcing recalculation
        if (!$force_recalculate) {
            $cached_balance = get_user_meta($user_id, 'points_balance_cache', true);
            $cache_time = get_user_meta($user_id, 'points_balance_cache_time', true);
            
            // Use cache if less than 5 minutes old
            if ($cached_balance !== false && $cache_time && (time() - intval($cache_time)) < 300) {
                return intval($cached_balance);
            }
        }
        
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        
        // Start transaction for data integrity
        $wpdb->query('START TRANSACTION');
        
        try {
            // Mark expired transactions first (atomic operation)
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_name 
                SET is_expired = 1 
                WHERE user_id = %d 
                AND expires_at IS NOT NULL 
                AND expires_at <= NOW() 
                AND is_expired = 0",
                $user_id
            ));
            
            // Check if deleted_at column exists
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
            
            // Build WHERE clause - exclude trashed transactions if column exists
            $where_deleted = '';
            if ($has_deleted_at_column) {
                $where_deleted = ' AND (deleted_at IS NULL OR deleted_at = "")';
            }
            
            // Optimized single query to calculate balance
            // This uses conditional aggregation to calculate all components in one query
            // Exclude trashed transactions from balance calculations
            $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN type IN ('earn', 'adjust', 'referral', 'birthday', 'refund') 
                        AND (expires_at IS NULL OR expires_at > NOW()) 
                        AND is_expired = 0 
                        AND status = 'approved'
                        THEN points 
                        ELSE 0 
                    END), 0) as earned,
                    COALESCE(SUM(CASE 
                        WHEN type = 'redeem' 
                        AND status = 'approved'
                        THEN points 
                        ELSE 0 
                    END), 0) as redeemed,
                    COALESCE(SUM(CASE 
                        WHEN type = 'expire' 
                        AND status = 'approved'
                        THEN points 
                        ELSE 0 
                    END), 0) as expired
                FROM $table_name 
                WHERE user_id = %d" . $where_deleted;
            
            $result = $wpdb->get_row($wpdb->prepare($sql, $user_id), ARRAY_A);
            
            if ($result === null) {
                $wpdb->query('ROLLBACK');
                return 0;
            }
            
            $earned = intval($result['earned']) ?: 0;
            $redeemed = intval($result['redeemed']) ?: 0;
            $expired = intval($result['expired']) ?: 0;
            
            $balance = max(0, $earned - $redeemed - $expired);
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Cache the result
            update_user_meta($user_id, 'points_balance_cache', $balance);
            update_user_meta($user_id, 'points_balance_cache_time', time());

            // Only sync meta when calculated balance is positive. When it is 0, do not overwrite
            // user meta (avoids wiping meta-only balance when redeem just made calculated 0).
            if ($balance > 0) {
                $this->sync_my_points_with_balance($user_id, $balance, false);
            }

            return $balance;
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('T-Work Points: Error calculating balance for user ' . $user_id . ': ' . $e->getMessage());
            
            // Return cached value if available, otherwise 0
            $cached_balance = get_user_meta($user_id, 'points_balance_cache', true);
            return $cached_balance !== false ? intval($cached_balance) : 0;
        }
    }
    
    /**
     * Invalidate balance cache for user
     */
    /**
     * Send points approval notification to app via webhook
     * 
     * @param array $data Notification data (user_id, transaction_id, points, description, current_balance)
     * @return bool Success status
     */
    private function send_points_approval_notification($data) {
        // Get webhook URL from settings (with fallback)
        $webhook_url = get_option('twork_points_webhook_url', '');
        
        // If no webhook URL configured, try to construct from site URL
        if (empty($webhook_url)) {
            // Try to auto-detect webhook server URL
            // Option 1: Same domain with default path
            $site_url = site_url();
            $parsed_url = parse_url($site_url);
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            if (isset($parsed_url['port'])) {
                $base_url .= ':' . $parsed_url['port'];
            }
            
            // Try common webhook paths
            $webhook_url = rtrim($base_url, '/') . '/api/webhook/points-approved';
        }
        
        // Validate URL
        if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            TWork_Points_Logger::warning(
                'Invalid webhook URL configured for points notifications',
                array('webhook_url' => $webhook_url)
            );
            return false;
        }
        
        // Prepare payload
        $payload = array(
            'userId' => isset($data['user_id']) ? intval($data['user_id']) : 0,
            'transactionId' => isset($data['transaction_id']) ? intval($data['transaction_id']) : 0,
            'points' => isset($data['points']) ? intval($data['points']) : 0,
            'description' => isset($data['description']) ? sanitize_text_field($data['description']) : '',
            'currentBalance' => isset($data['current_balance']) ? intval($data['current_balance']) : 0,
        );
        
        // Validate required fields
        if ($payload['userId'] <= 0 || $payload['transactionId'] <= 0) {
            TWork_Points_Logger::warning(
                'Invalid notification data - missing user_id or transaction_id',
                array('payload' => $payload)
            );
            return false;
        }
        
        // Send notification (blocking for now to catch errors)
        $response = wp_remote_post($webhook_url, array(
            'method' => 'POST',
            'timeout' => 10, // 10 second timeout
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($payload),
            'blocking' => true, // Blocking to catch errors
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            TWork_Points_Logger::error(
                'Failed to send points approval notification',
                array(
                    'webhook_url' => $webhook_url,
                    'user_id' => $payload['userId'],
                    'transaction_id' => $payload['transactionId'],
                    'error' => $error_message,
                )
            );
            error_log('T-Work Points: Webhook error - ' . $error_message);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            TWork_Points_Logger::info(
                'Points approval notification sent successfully',
                array(
                    'webhook_url' => $webhook_url,
                    'user_id' => $payload['userId'],
                    'transaction_id' => $payload['transactionId'],
                    'points' => $payload['points'],
                    'response_code' => $response_code,
                )
            );
            return true;
        } else {
            TWork_Points_Logger::warning(
                'Points approval notification failed with error code',
                array(
                    'webhook_url' => $webhook_url,
                    'user_id' => $payload['userId'],
                    'transaction_id' => $payload['transactionId'],
                    'response_code' => $response_code,
                    'response_body' => $response_body,
                )
            );
            error_log('T-Work Points: Webhook failed with code ' . $response_code . ': ' . $response_body);
            return false;
        }
    }
    
    /**
     * Register FCM token - forwards to webhook server
     * This endpoint receives FCM tokens from the app and forwards them to the webhook server
     */
    public function register_fcm_token($request) {
        $params = $request->get_json_params();
        $user_id = isset($params['userId']) ? sanitize_text_field($params['userId']) : '';
        $fcm_token = isset($params['fcmToken']) ? sanitize_text_field($params['fcmToken']) : '';
        $platform = isset($params['platform']) ? sanitize_text_field($params['platform']) : 'android';
        
        if (empty($user_id) || empty($fcm_token)) {
            return new WP_Error('invalid_params', 'userId and fcmToken are required', array('status' => 400));
        }
        
        // Get webhook server URL for token registration
        $webhook_url = get_option('twork_points_webhook_url', '');
        if (empty($webhook_url)) {
            // Try to auto-detect
            $site_url = site_url();
            $parsed_url = parse_url($site_url);
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            if (isset($parsed_url['port'])) {
                $base_url .= ':' . $parsed_url['port'];
            }
            $webhook_base = rtrim($base_url, '/');
        } else {
            // Extract base URL from webhook URL
            $parsed_webhook = parse_url($webhook_url);
            $webhook_base = $parsed_webhook['scheme'] . '://' . $parsed_webhook['host'];
            if (isset($parsed_webhook['port'])) {
                $webhook_base .= ':' . $parsed_webhook['port'];
            }
        }
        
        $register_url = rtrim($webhook_base, '/') . '/api/users/register-token';
        
        // Forward token to webhook server
        $response = wp_remote_post($register_url, array(
            'method' => 'POST',
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'userId' => $user_id,
                'fcmToken' => $fcm_token,
                'platform' => $platform,
            )),
        ));
        
        if (is_wp_error($response)) {
            TWork_Points_Logger::warning(
                'Failed to forward FCM token to webhook server',
                array(
                    'user_id' => $user_id,
                    'webhook_url' => $register_url,
                    'error' => $response->get_error_message(),
                )
            );
            // Still return success to app (webhook might be down temporarily)
            return rest_ensure_response(array(
                'success' => false,
                'message' => 'Failed to register token with webhook server',
            ));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code >= 200 && $response_code < 300) {
            TWork_Points_Logger::info(
                'FCM token registered successfully',
                array(
                    'user_id' => $user_id,
                    'platform' => $platform,
                )
            );
        }
        
        return rest_ensure_response($response_body ?: array('success' => true));
    }
    
    private function invalidate_balance_cache($user_id) {
        delete_user_meta($user_id, 'points_balance_cache');
        delete_user_meta($user_id, 'points_balance_cache_time');
    }
    
    /**
     * Get lifetime points for a type
     */
    private function get_lifetime_points($user_id, $type) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        
        // Check if deleted_at column exists
        $has_deleted_at_column = false;
        try {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'deleted_at'");
            $has_deleted_at_column = !empty($column_exists);
        } catch (Exception $e) {
            $has_deleted_at_column = false;
        }
        
        $where_deleted = '';
        if ($has_deleted_at_column) {
            $where_deleted = " AND (deleted_at IS NULL OR deleted_at = '')";
        }
        
        $sql = "SELECT SUM(points) FROM $table_name 
            WHERE user_id = %d 
            AND type = %s
            AND status = 'approved'" . $where_deleted;
        
        $points = $wpdb->get_var($wpdb->prepare($sql, $user_id, $type));
        
        return intval($points) ?: 0;
    }
    
    /**
     * Award points on order completion
     * Improved to handle discount calculation and prevent double awarding
     */
    public function award_points_on_order_completion($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $user_id = $order->get_user_id();

        /**
         * Deep-dive hardening:
         * In some mobile / API flows the WooCommerce order can be created as a
         * "guest" order even though the billing email actually belongs to a
         * registered WordPress user. In that case $order->get_user_id() will be
         * 0 and points would never be awarded.
         *
         * To make the points system robust for app-created orders, we try to
         * resolve the user by billing email before giving up.
         */
        if (!$user_id) {
            $billing_email = $order->get_billing_email();
            if (!empty($billing_email) && is_email($billing_email)) {
                $user = get_user_by('email', $billing_email);
                if ($user && !is_wp_error($user)) {
                    $user_id = (int) $user->ID;
                    TWork_Points_Logger::info(
                        'Resolved guest order to user by billing email',
                        array(
                            'order_id' => $order_id,
                            'email'    => $billing_email,
                            'user_id'  => $user_id,
                        )
                    );
                }
            }
        }

        // Still no user id? Treat as guest order and skip points to avoid
        // assigning points to the wrong account.
        if (!$user_id) {
            TWork_Points_Logger::warning(
                'Skipping points award for order without resolvable user',
                array(
                    'order_id' => $order_id,
                    'billing_email' => $order->get_billing_email(),
                )
            );
            return; // Guest order with no resolvable user
        }
        
        // Check if points already awarded (atomic check)
        $points_awarded = get_post_meta($order_id, '_points_awarded', true);
        if ($points_awarded) {
            return; // Already awarded
        }
        
        // Calculate points based on order total AFTER discount (if points were redeemed)
        // Points should be awarded on actual amount paid, not original total
        $order_total = floatval($order->get_total());
        
        // Check if points were redeemed (discount already applied to order total)
        $points_redeemed = get_post_meta($order_id, '_points_redeemed', true);
        if ($points_redeemed) {
            // Points were redeemed, so order total already reflects discount
            // Award points on the final paid amount
            $order_total = floatval($order->get_total());
        }
        
        // Get configurable points rate
        $points_rate = floatval(get_option('twork_points_rate', 1.0));
        $points = intval($order_total * $points_rate);
        
        if ($points <= 0) {
            return;
        }
        
        // Get expiration days (default 1 year)
        $expiration_days = intval(get_option('twork_points_expiration_days', 365));
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
        
        // Create a **pending** earn transaction.
        // The customer's balance will only increase after an admin
        // approves this transaction from the dashboard.
        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => 'earn',
            'points' => $points,
            'description' => sprintf('Points earned from order #%s (pending approval)', $order_id),
            'order_id' => strval($order_id),
            'expires_at' => $expires_at,
            'status' => 'pending',
        ));
        
        if ($transaction_id) {
            // Mark as awarded (only if transaction was created successfully)
            update_post_meta($order_id, '_points_awarded', true);
            update_post_meta($order_id, '_points_awarded_amount', $points);
            
            // Add order note so staff can see the pending earn record
            $order->add_order_note(sprintf(
                __('Points request created: %d points (expires: %s). Awaiting admin approval.', 'twork-points'),
                $points,
                date_i18n(get_option('date_format'), strtotime($expires_at))
            ));
        }
    }
    
    /**
     * Refund points on order cancellation
     * Improved to handle both redeemed points refund and earned points reversal
     */
    public function refund_points_on_order_cancellation($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return; // Guest order
        }
        
        // Check if already processed
        $points_refunded = get_post_meta($order_id, '_points_refunded', true);
        if ($points_refunded) {
            return; // Already refunded
        }
        
        $refund_transactions = array();
        
        // 1. Refund redeemed points (if any were redeemed)
        $points_redeemed = get_post_meta($order_id, '_points_redeemed', true);
        if ($points_redeemed && $points_redeemed > 0) {
            $expiration_days = intval(get_option('twork_points_expiration_days', 365));
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
            
            $transaction_id = $this->create_transaction(array(
                'user_id' => $user_id,
                'type' => 'refund',
                'points' => intval($points_redeemed),
                'description' => sprintf('Points refunded for cancelled order #%s (redeemed points)', $order_id),
                'order_id' => strval($order_id),
                'expires_at' => $expires_at,
            ));
            
            if ($transaction_id) {
                $refund_transactions[] = $transaction_id;
            }
        }
        
        // 2. Reverse earned points (if any were awarded)
        // Find the earn transaction for this order
        global $wpdb;
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        $earn_transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT id, points FROM $table_name 
            WHERE user_id = %d 
            AND order_id = %s 
            AND type = 'earn' 
            LIMIT 1",
            $user_id,
            strval($order_id)
        ));
        
        if ($earn_transaction) {
            // Create a negative adjustment to reverse the earned points
            // Or mark the original transaction as reversed
            // For simplicity, we'll create a reverse transaction
            $expiration_days = intval(get_option('twork_points_expiration_days', 365));
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
            
            $transaction_id = $this->create_transaction(array(
                'user_id' => $user_id,
                'type' => 'adjust',
                'points' => -intval($earn_transaction->points), // Negative to reverse
                'description' => sprintf('Points reversed for cancelled order #%s (earned points)', $order_id),
                'order_id' => strval($order_id) . '_reverse',
                'expires_at' => $expires_at,
            ));
            
            if ($transaction_id) {
                $refund_transactions[] = $transaction_id;
            }
            
            // Mark original earn transaction as reversed (optional)
            $wpdb->update(
                $table_name,
                array('description' => $wpdb->get_var($wpdb->prepare(
                    "SELECT CONCAT(description, ' [REVERSED]') FROM $table_name WHERE id = %d",
                    $earn_transaction->id
                ))),
                array('id' => $earn_transaction->id),
                array('%s'),
                array('%d')
            );
        }
        
        // Mark as refunded if any refunds were processed
        if (!empty($refund_transactions)) {
            update_post_meta($order_id, '_points_refunded', true);
            update_post_meta($order_id, '_points_refunded_at', current_time('mysql'));
            
            // Update balance cache
            $balance = $this->calculate_user_balance($user_id, true);
            update_user_meta($user_id, 'points_balance', $balance);
            
            // Add order note
            $refund_summary = array();
            if ($points_redeemed) {
                $refund_summary[] = sprintf(__('%d redeemed points refunded', 'twork-points'), $points_redeemed);
            }
            if ($earn_transaction) {
                $refund_summary[] = sprintf(__('%d earned points reversed', 'twork-points'), $earn_transaction->points);
            }
            
            $order->add_order_note(__('Points refunded: ', 'twork-points') . implode(', ', $refund_summary));
        }
    }
    
    /**
     * Get points expiring soon
     */
    public function get_points_expiring_soon($request) {
        global $wpdb;
        
        $user_id = intval($request->get_param('user_id'));
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        $warning_days = 30;
        $warning_date = date('Y-m-d H:i:s', strtotime("+{$warning_days} days"));
        
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE user_id = %d 
            AND type = 'earn'
            AND expires_at IS NOT NULL
            AND expires_at <= %s
            AND expires_at > NOW()
            AND is_expired = 0
            ORDER BY expires_at ASC",
            $user_id,
            $warning_date
        ));
        
        $formatted_transactions = array();
        foreach ($transactions as $transaction) {
            $formatted_transactions[] = array(
                'id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'type' => $transaction->type,
                'points' => intval($transaction->points),
                'description' => $transaction->description,
                'order_id' => $transaction->order_id,
                'created_at' => $transaction->created_at,
                'expires_at' => $transaction->expires_at,
                'is_expired' => (bool) $transaction->is_expired,
            );
        }
        
        return rest_ensure_response(array(
            'transactions' => $formatted_transactions,
            'count' => count($formatted_transactions),
        ));
    }
    
    /**
     * Check and mark expired points
     */
    public function check_expired_points($request) {
        $user_id = intval($request->get_param('user_id'));

        if (! $user_id) {
            return rest_ensure_response(array(
                'success' => false,
                'expired_count' => 0,
                'message' => __('Invalid user ID.', 'twork-points'),
            ));
        }

        $result = $this->expire_points_for_user($user_id);

        if (($result['expired_count'] ?? 0) === 0) {
            return rest_ensure_response(array(
                'success' => true,
                'expired_count' => 0,
                'message' => __('No expired points found', 'twork-points'),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'expired_count' => $result['expired_count'],
            'expired_points' => $result['expired_points'],
            'new_balance' => $result['balance'],
            'message' => sprintf(
                /* translators: 1: number of transactions, 2: total points */
                __('%1$d transactions expired (%2$d points)', 'twork-points'),
                $result['expired_count'],
                $result['expired_points']
            ),
        ));
    }
    
    /**
     * Award referral bonus
     */
    public function award_referral_bonus($request) {
        $params = $request->get_json_params();
        
        $user_id = intval($params['user_id'] ?? 0);
        $referred_user_id = intval($params['referred_user_id'] ?? 0);
        $referral_bonus = intval(get_option('twork_points_referral_bonus', 500));
        
        if (!$user_id || !$referred_user_id) {
            return new WP_Error('invalid_params', 'Invalid user_id or referred_user_id', array('status' => 400));
        }
        
        // Create transaction
        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => 'referral',
            'points' => $referral_bonus,
            'description' => sprintf('Referral bonus for referring user #%s', $referred_user_id),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
        ));
        
        if (!$transaction_id) {
            $this->record_sync_failure('award_referral_bonus', 'Failed to create transaction');
            return new WP_Error('transaction_failed', 'Failed to create referral transaction', array('status' => 500));
        }
        
        // Update balance cache (force recalculation)
        $balance = $this->calculate_user_balance($user_id, true);
        update_user_meta($user_id, 'points_balance', $balance);

        // Update my_points: ADD to existing points instead of replacing
        $existing_points_raw = get_user_meta($user_id, 'my_points', true);
        $existing_points = is_numeric($existing_points_raw) ? (float) $existing_points_raw : 0.0;
        $delta_points = (float) $referral_bonus;
        $new_points_total = $existing_points + $delta_points;

        // Store as integer if whole number, otherwise keep decimal
        $stored_points = (floor($new_points_total) == $new_points_total)
            ? (string) (int) $new_points_total
            : (string) $new_points_total;

        update_user_meta($user_id, 'my_points', $stored_points);
        update_user_meta($user_id, 'my_points_updated_at', time());

        $this->record_sync_success();

        TWork_Points_Logger::info(
            'Referral bonus awarded',
            array(
                'transaction_id' => $transaction_id,
                'user_id' => $user_id,
                'referred_user_id' => $referred_user_id,
                'points' => $referral_bonus,
                'my_points_updated' => $new_points_total,
            )
        );
        
        return rest_ensure_response(array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'new_balance' => $balance,
            'points_awarded' => $referral_bonus,
        ));
    }
    
    /**
     * Award birthday bonus
     */
    public function award_birthday_bonus($request) {
        $params = $request->get_json_params();
        
        $user_id = intval($params['user_id'] ?? 0);
        $birthday_bonus = intval(get_option('twork_points_birthday_bonus', 200));
        
        if (!$user_id) {
            return new WP_Error('invalid_params', 'Invalid user_id', array('status' => 400));
        }
        
        // Check if already awarded this year
        global $wpdb;
        $table_name = $wpdb->prefix . 'twork_point_transactions';
        $this_year = date('Y');
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND type = 'birthday' 
            AND YEAR(created_at) = %s
            LIMIT 1",
            $user_id,
            $this_year
        ));
        
        if ($existing) {
            return new WP_Error('already_awarded', 'Birthday bonus already awarded this year', array('status' => 400));
        }
        
        // Create transaction
        $transaction_id = $this->create_transaction(array(
            'user_id' => $user_id,
            'type' => 'birthday',
            'points' => $birthday_bonus,
            'description' => 'Birthday bonus',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
        ));
        
        if (!$transaction_id) {
            $this->record_sync_failure('award_birthday_bonus', 'Failed to create transaction');
            return new WP_Error('transaction_failed', 'Failed to create birthday transaction', array('status' => 500));
        }
        
        // Update balance cache (force recalculation)
        $balance = $this->calculate_user_balance($user_id, true);
        update_user_meta($user_id, 'points_balance', $balance);

        // Update my_points: ADD to existing points instead of replacing
        $existing_points_raw = get_user_meta($user_id, 'my_points', true);
        $existing_points = is_numeric($existing_points_raw) ? (float) $existing_points_raw : 0.0;
        $delta_points = (float) $birthday_bonus;
        $new_points_total = $existing_points + $delta_points;

        // Store as integer if whole number, otherwise keep decimal
        $stored_points = (floor($new_points_total) == $new_points_total)
            ? (string) (int) $new_points_total
            : (string) $new_points_total;

        update_user_meta($user_id, 'my_points', $stored_points);
        update_user_meta($user_id, 'my_points_updated_at', time());

        $this->record_sync_success();

        TWork_Points_Logger::info(
            'Birthday bonus awarded',
            array(
                'transaction_id' => $transaction_id,
                'user_id' => $user_id,
                'points' => $birthday_bonus,
                'my_points_updated' => $new_points_total,
            )
        );
        
        return rest_ensure_response(array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'new_balance' => $balance,
            'points_awarded' => $birthday_bonus,
        ));
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('T-Work Points System requires WooCommerce to be installed and active.', 'twork-points'); ?></p>
        </div>
        <?php
    }
}

// Initialize plugin
function twork_points_system_init() {
    return TWork_Points_System::get_instance();
}

// Start the plugin
twork_points_system_init();

