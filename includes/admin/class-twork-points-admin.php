<?php
/**
 * T-Work Points System - Admin Interface
 * 
 * @package TWorkPoints
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin interface class
 */
class TWork_Points_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Admin functionality will be added to main plugin class
    }
}


