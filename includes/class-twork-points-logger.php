<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Minimal PSR-3 style logger with rotation support for the points plugin.
 */
class TWork_Points_Logger {
    private const LOG_DIR = 'twork-points/logs';
    private const LOG_FILE = 'points.log';
    private const MAX_FILE_SIZE = 5242880; // 5 MB

    /**
     * Logs a message.
     *
     * @param string $level   Log level.
     * @param string $message Message to log.
     * @param array  $context Additional context.
     */
    public static function log(string $level, string $message, array $context = array()): void {
        $upload_dir = wp_upload_dir();
        if (! empty($upload_dir['error'])) {
            error_log(sprintf('[TWorkPoints:%s] %s - %s', strtoupper($level), $message, $upload_dir['error']));
            return;
        }

        $directory = trailingslashit($upload_dir['basedir']) . self::LOG_DIR;
        if (! wp_mkdir_p($directory)) {
            error_log(sprintf('[TWorkPoints:%s] %s - failed to create log directory', strtoupper($level), $message));
            return;
        }

        $file = trailingslashit($directory) . self::LOG_FILE;
        self::rotate_if_necessary($file);

        $log_entry = sprintf(
            "[%s] [%s] %s%s\n",
            gmdate('c'),
            strtoupper($level),
            $message,
            empty($context) ? '' : ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        error_log($log_entry, 3, $file);
    }

    public static function debug(string $message, array $context = array()): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log('debug', $message, $context);
        }
    }

    public static function info(string $message, array $context = array()): void {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = array()): void {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = array()): void {
        self::log('error', $message, $context);
    }

    public static function critical(string $message, array $context = array()): void {
        self::log('critical', $message, $context);
    }

    private static function rotate_if_necessary(string $file): void {
        if (! file_exists($file)) {
            return;
        }

        if (filesize($file) < self::MAX_FILE_SIZE) {
            return;
        }

        $rotated = $file . '.' . gmdate('Ymd_His');
        rename($file, $rotated);
    }
}

