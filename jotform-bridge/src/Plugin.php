<?php

declare(strict_types=1);

namespace JotformBridge;

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Admin\SettingsPage;
use JotformBridge\Api\ConnectionState;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\CompatibilityChecker;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Rendering\Assets;
use JotformBridge\Rendering\CustomTemplateRenderer;
use JotformBridge\Rendering\FormRenderer;
use JotformBridge\Rest\SubmissionController;
use JotformBridge\Settings\Settings;
use JotformBridge\Submission\SubmissionPipeline;
use JotformBridge\Support\Logger;
use JotformBridge\Templates\TemplateRegistry;

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

    private ?IntegrationRepository $integrations = null;

    private ?TemplateRegistry $templates = null;

    private ?Assets $assets = null;

    private ?FormRenderer $renderer = null;

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

        // Rendering and submitting are front-end concerns, but the REST route
        // must also exist for a logged-in editor previewing a page.
        add_action('wp_enqueue_scripts', [$this->assets(), 'register']);

        add_shortcode('jotform_form', [$this->renderer(), 'shortcode']);

        (new SubmissionController($this->pipeline()))->register();

        if (is_admin()) {
            // Registration order decides the submenu order: Integrations first.
            (new IntegrationsPage(
                $this->integrations(),
                $this->forms(),
                $this->schemas(),
                $this->templates(),
                $this->compatibility()
            ))->register();

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

    public function integrations(): IntegrationRepository
    {
        if ($this->integrations === null) {
            $this->integrations = new IntegrationRepository();
        }

        return $this->integrations;
    }

    public function templates(): TemplateRegistry
    {
        if ($this->templates === null) {
            $this->templates = new TemplateRegistry();
        }

        return $this->templates;
    }

    public function assets(): Assets
    {
        if ($this->assets === null) {
            $this->assets = new Assets();
        }

        return $this->assets;
    }

    /**
     * The service behind both `jotform_form()` and the shortcode.
     */
    public function renderer(): FormRenderer
    {
        if ($this->renderer === null) {
            $this->renderer = new FormRenderer(
                $this->integrations(),
                $this->schemas(),
                new CustomTemplateRenderer($this->templates()),
                $this->assets(),
                $this->logger
            );
        }

        return $this->renderer;
    }

    public function pipeline(): SubmissionPipeline
    {
        return new SubmissionPipeline(
            $this->integrations(),
            $this->schemas(),
            $this->client(),
            null,
            null,
            null,
            $this->logger
        );
    }

    public function compatibility(): CompatibilityChecker
    {
        return new CompatibilityChecker($this->schemas(), $this->templates());
    }

    /**
     * Caches are disposable; configuration is left untouched on deactivation.
     */
    public static function onDeactivate(): void
    {
        delete_transient(FormRepository::TRANSIENT);
        SchemaRepository::flushAll();

        // The template registry is a filesystem cache: it is rebuilt on demand.
        delete_option(TemplateRegistry::OPTION);
    }
}
