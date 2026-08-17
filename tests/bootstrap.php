<?php

/**
 * PHPUnit bootstrap.
 *
 * Domain tests run without WordPress: Brain Monkey stubs the WordPress
 * functions, and this file only provides the constants and classes that cannot
 * be expressed as function stubs.
 *
 * @package JotformBridge\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// The plugin files bail out unless they are loaded inside WordPress.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal stand-in for the WordPress error object.
     */
    class WP_Error
    {
        /** @var string */
        private $code;

        /** @var string */
        private $message;

        public function __construct(string $code = '', string $message = '')
        {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
