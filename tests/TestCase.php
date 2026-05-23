<?php

namespace TWorkPointsSystem\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Monkey\setUp();
        $this->stubWordPressFunctions();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../../../');
        }

        if (!class_exists('TWork_Points_System')) {
            require_once __DIR__ . '/../twork-points-system.php';
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubWordPressFunctions(): void
    {
        $noOpFunctions = [
            'register_activation_hook',
            'register_deactivation_hook',
            'add_action',
            'add_filter',
            'add_menu_page',
            'add_submenu_page',
            'admin_notices',
            'wp_enqueue_style',
            'wp_enqueue_script',
            'load_plugin_textdomain',
            'wp_die',
        ];

        foreach ($noOpFunctions as $function) {
            Functions\when($function)->justReturn(true);
        }

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_create_nonce')->returnArg(0);
        Functions\when('admin_url')->alias(function (string $path = '') {
            return 'http://example.com/wp-admin/' . ltrim($path, '/');
        });
        Functions\when('plugin_dir_path')->alias(static function ($file) {
            return dirname($file) . '/';
        });
        Functions\when('plugin_dir_url')->alias(static function ($file) {
            return 'http://example.com/' . basename(dirname($file)) . '/';
        });
        Functions\when('sanitize_text_field')->returnArg(0);
        Functions\when('wp_unslash')->returnArg(0);
        Functions\when('esc_attr')->returnArg(0);
        Functions\when('esc_html')->returnArg(0);
        Functions\when('add_query_arg')->alias(static function ($args, $url) {
            return $url . '?' . http_build_query($args);
        });
        Functions\when('wp_safe_redirect')->justReturn(true);
        Functions\when('rest_ensure_response')->returnArg(0);
        Functions\when('get_option')->alias(static function ($key, $default = null) {
            return $default;
        });
        Functions\when('is_numeric')->alias(static fn($value) => \is_numeric($value));
        Functions\when('current_time')->alias(static fn() => '2025-01-01 00:00:00');
    }
}

