<?php

declare(strict_types=1);

namespace JotformBridge;

use JotformBridge\Admin\SettingsPage;
use JotformBridge\Api\ConnectionState;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Settings\Settings;
use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Composition root.
 *
 * Holds the services and wires them into WordPress. No business logic lives here.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private Settings $settings;

    private Logger $logger;

    private ?JotformClient $client = null;

    private ?FormRepository $forms = null;

    private ?SchemaRepository $schemas = null;

    private ConnectionState $connection;

    private bool $booted = false;

    private function __construct()
    {
        $this->settings   = new Settings();
        $this->logger     = new Logger($this->settings);
        $this->connection = new ConnectionState();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action(
            'init',
            static function (): void {
                load_plugin_textdomain(
                    'jotform-bridge',
                    false,
                    dirname(plugin_basename(JOTFORM_BRIDGE_FILE)) . '/languages'
                );
            }
        );

        if (is_admin()) {
            (new SettingsPage(
                $this->settings,
                $this->client(),
                $this->forms(),
                $this->connection
            ))->register();
        }
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function connection(): ConnectionState
    {
        return $this->connection;
    }

    /**
     * Built lazily so a settings change during the request is picked up.
     */
    public function client(): JotformClient
    {
        if ($this->client === null) {
            $this->client = JotformClient::fromSettings($this->settings, $this->logger);
        }

        return $this->client;
    }

    public function forms(): FormRepository
    {
        if ($this->forms === null) {
            $this->forms = new FormRepository($this->client());
        }

        return $this->forms;
    }

    public function schemas(): SchemaRepository
    {
        if ($this->schemas === null) {
            $this->schemas = new SchemaRepository($this->client());
        }

        return $this->schemas;
    }

    /**
     * Caches are disposable; configuration is left untouched on deactivation.
     */
    public static function onDeactivate(): void
    {
        delete_transient(FormRepository::TRANSIENT);
        SchemaRepository::flushAll();
    }
}
