<?php

declare(strict_types=1);

namespace JotformBridge;

use JotformBridge\Admin\ApiKeyNotice;
use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Admin\SettingsPage;
use JotformBridge\Api\ConnectionState;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\CompatibilityChecker;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Rendering\Assets;
use JotformBridge\Rendering\AutoRenderer;
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
    /**
     * Version the caches were last built for. Not user configuration: it is
     * bookkeeping, and losing it only costs one rebuild.
     */
    public const VERSION_OPTION = 'jotform_bridge_version';

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

        self::maybeUpgrade();

        // Template discovery is theme-scoped: the registry holds absolute paths
        // in the theme that was active when it was built, so it cannot survive a
        // theme switch.
        add_action('switch_theme', [self::class, 'flushTemplateRegistry']);

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
            (new ApiKeyNotice($this->settings))->register();

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
                $this->logger,
                new AutoRenderer()
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
     * Activation does the least it can get away with: no schema fetch, no
     * filesystem scan, no default integrations. Everything the plugin needs is
     * built on demand, so there is nothing here that could fatal on a site with
     * no API key yet.
     */
    public static function onActivate(): void
    {
        self::flushCaches();
        Settings::purgeStoredKey();

        update_option(self::VERSION_OPTION, JOTFORM_BRIDGE_VERSION, false);
    }

    /**
     * Caches are disposable; configuration is left untouched on deactivation.
     */
    public static function onDeactivate(): void
    {
        self::flushCaches();
    }

    /**
     * Drops the derived state that is cheap to rebuild.
     *
     * Synced schemas are deliberately not part of this. They are written only by
     * an explicit per-integration Sync and nothing rebuilds them on its own, so
     * dropping them here would take every form on the site down until somebody
     * noticed and clicked through each integration by hand.
     *
     * Static because deactivation and activation have no built services to work
     * with. Configuration — settings, integrations — is never touched here.
     */
    public static function flushCaches(): void
    {
        self::flushTemplateRegistry();
    }

    /**
     * The template registry is a filesystem cache: it is rebuilt on demand.
     */
    public static function flushTemplateRegistry(): void
    {
        delete_option(TemplateRegistry::OPTION);
    }

    /**
     * Discards derived state after an upgrade.
     *
     * A new version may store a registry entry differently, and a scan written
     * by the previous version is not worth trusting. Synced schemas survive: a
     * new version may normalize them differently, but that is reported as
     * "synced by an older version — re-sync recommended" on the integration
     * screen rather than acted on behind the site owner's back. The one thing
     * rewritten here is the settings option: a key stored by a version that
     * still accepted one has to leave the database.
     */
    private static function maybeUpgrade(): void
    {
        $stored = get_option(self::VERSION_OPTION, '');

        if (is_string($stored) && $stored === JOTFORM_BRIDGE_VERSION) {
            return;
        }

        self::flushCaches();
        Settings::purgeStoredKey();

        // Storage the plugin no longer uses, left behind by an older version.
        SchemaRepository::purgeLegacyTransients();
        delete_transient(FormRepository::LEGACY_TRANSIENT);

        update_option(self::VERSION_OPTION, JOTFORM_BRIDGE_VERSION, false);
    }
}
