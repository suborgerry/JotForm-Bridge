<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration;

use Closure;
use JotformBridge\Plugin;
use ReflectionFunction;
use ReflectionProperty;

/**
 * Puts the plugin back into the state a fresh HTTP request would find it in.
 *
 * A site boots the plugin once per request: new services, empty memos, hooks
 * registered against those services. A PHPUnit process boots it once for fifty
 * tests, and the difference is not academic — IntegrationRepository, Settings,
 * FormRepository and TemplateRegistry all memoize within "the request", quite
 * correctly, because nothing else writes their storage inside one. A suite that
 * kept the first request's objects would be asserting against answers cached
 * before the test set anything up.
 *
 * So each test gets a new request: the callbacks bound to the previous set of
 * services are removed, the composition root is discarded, and the plugin is
 * booted again exactly as wp-settings.php boots it.
 */
final class Runtime
{
    /**
     * Storage the plugin owns. Everything else in the options table belongs to
     * WordPress and is left alone.
     */
    private const OPTION_PATTERNS = [
        'jotform_bridge_%',
        '_transient_jotform_bridge_%',
        '_transient_timeout_jotform_bridge_%',
        '_site_transient_jotform_bridge_%',
        '_site_transient_timeout_jotform_bridge_%',
    ];

    /**
     * A fresh request against a database with none of the plugin's own state
     * in it.
     */
    public static function newRequest(): void
    {
        self::purgeStorage();
        self::reboot();
    }

    /**
     * A fresh request against whatever is stored right now.
     *
     * The lifecycle tests need this: what an upgrade does to storage written by
     * an older version is only visible if the storage survives into the boot.
     */
    public static function reboot(): void
    {
        self::forgetPluginCallbacks();

        $instance = new ReflectionProperty(Plugin::class, 'instance');

        // Needed on PHP 8.0, which the plugin still supports; deprecated from
        // PHP 8.5, where private members are readable without it.
        if (PHP_VERSION_ID < 80100) {
            $instance->setAccessible(true);
        }

        $instance->setValue(null, null);

        // The REST server registers routes once, on the first rest_api_init.
        // Left alone it would keep answering through the controller that has
        // just been discarded.
        $GLOBALS['wp_rest_server'] = null;

        $GLOBALS['current_screen'] = new AdminScreen();

        Plugin::instance()->boot();

        unset($GLOBALS['current_screen']);
    }

    /**
     * Deletes every option and transient the plugin stores.
     */
    public static function purgeStorage(): void
    {
        global $wpdb;

        foreach (self::OPTION_PATTERNS as $pattern) {
            $names = $wpdb->get_col(
                $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern)
            );

            foreach ((array) $names as $name) {
                // Through the API rather than by DELETE, so the object cache
                // does not keep answering with what was just removed.
                delete_option((string) $name);
            }
        }
    }

    /**
     * Removes the hooks the plugin's own boot registered.
     *
     * Everything attributable to a class in the plugin's namespace goes, so the
     * next boot registers one of each rather than another copy. The two
     * exceptions are the lifecycle hooks: `activate_...` and `deactivate_...`
     * are registered when the plugin file is included, which happens once per
     * process and never again, and they carry static methods that cannot go
     * stale. Sweeping them would quietly disarm every activation test in the
     * suite.
     */
    private static function forgetPluginCallbacks(): void
    {
        foreach ($GLOBALS['wp_filter'] as $name => $hook) {
            if (strncmp((string) $name, 'activate_', 9) === 0 || strncmp((string) $name, 'deactivate_', 11) === 0) {
                continue;
            }

            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $registered) {
                    $class = self::attributedClass($registered['function']);

                    if ($class === '' || strncmp($class, 'JotformBridge\\', 14) !== 0) {
                        continue;
                    }

                    remove_filter((string) $name, $registered['function'], (int) $priority);
                }
            }
        }
    }

    /**
     * The class a callback belongs to: the object it is bound to, the scope a
     * closure was written in, or the class named in a static callback.
     *
     * @param mixed $callback
     */
    private static function attributedClass($callback): string
    {
        if (is_array($callback) && isset($callback[0])) {
            return is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
        }

        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);
            $bound      = $reflection->getClosureThis();

            if ($bound !== null) {
                return get_class($bound);
            }

            $scope = $reflection->getClosureScopeClass();

            return $scope === null ? '' : $scope->getName();
        }

        if (is_object($callback)) {
            return get_class($callback);
        }

        if (is_string($callback) && strpos($callback, '::') !== false) {
            return (string) strstr($callback, '::', true);
        }

        return '';
    }
}
