<?php

declare(strict_types=1);

namespace JotformBridge;

use JotformBridge\Admin\AdminAssets;
use JotformBridge\Admin\ApiKeyNotice;
use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Admin\QuotaNotice;
use JotformBridge\Admin\SettingsPage;
use JotformBridge\Admin\TurnstileNotice;
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
use JotformBridge\Submission\Guards\Honeypot;
use JotformBridge\Submission\Guards\MinimumTime;
use JotformBridge\Submission\Guards\ProofOfWork;
use JotformBridge\Submission\Guards\Turnstile;
use JotformBridge\Submission\QuotaGuard;
use JotformBridge\Submission\SubmissionPipeline;
use JotformBridge\Submission\TestSubmission;
use JotformBridge\Support\Logger;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Updates\GitHubUpdater;

if (!defined('ABSPATH')) {
    exit;
}

/** Composition root: holds the services and wires them into WordPress. */
final class Plugin
{
    /** Plugin version the upgrade step last ran for. */
    public const VERSION_OPTION = 'jotform_bridge_version';

    /** Who may configure the plugin and see why a form did not render. */
    public const CAPABILITY = 'manage_options';

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

    private ?QuotaGuard $quota = null;

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

        // Frontend services are built lazily, inside the callbacks.
        add_action(
            'wp_enqueue_scripts',
            static function (): void {
                self::instance()->assets()->register();
            }
        );

        add_shortcode(
            'jotform_form',
            static function ($atts): string {
                return self::instance()->renderer()->shortcode($atts);
            }
        );

        // Shipped spam providers register on the same filter a third-party one would.
        (new Honeypot())->register();
        (new MinimumTime())->register();
        (new ProofOfWork())->register();
        (new Turnstile($this->logger))->register();

        (new SubmissionController(static fn(): SubmissionPipeline => self::instance()->pipeline()))->register();

        // Not under is_admin(): the update check also runs from cron.
        (new GitHubUpdater($this->logger))->register();

        if (is_admin()) {
            (new AdminAssets())->register();
            (new ApiKeyNotice($this->settings))->register();
            (new QuotaNotice($this->quota()))->register();
            (new TurnstileNotice())->register();

            // Registration order decides the submenu order.
            (new IntegrationsPage(
                $this->integrations(),
                $this->forms(),
                $this->schemas(),
                $this->templates(),
                $this->compatibility(),
                new TestSubmission($this->schemas(), $this->client(), $this->quota())
            ))->register();

            (new SettingsPage(
                $this->settings,
                $this->client(),
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
            $this->templates = new TemplateRegistry($this->logger);
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

    /** The service behind both `jotform_bridge_render()` and the shortcode. */
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

    public function quota(): QuotaGuard
    {
        if ($this->quota === null) {
            $this->quota = new QuotaGuard();
        }

        return $this->quota;
    }

    public function pipeline(): SubmissionPipeline
    {
        return new SubmissionPipeline(
            $this->integrations(),
            $this->schemas(),
            $this->client(),
            $this->quota(),
            $this->logger
        );
    }

    public function compatibility(): CompatibilityChecker
    {
        return new CompatibilityChecker($this->schemas(), $this->templates());
    }

    /**
     * Purges legacy storage and records the version. No fetch, no scan.
     *
     * @param bool $networkWide True for a network-wide activation, which fires once.
     */
    public static function onActivate(bool $networkWide = false): void
    {
        self::eachSite(
            static function (): void {
                Settings::purgeStoredKey();
                self::purgeLegacyStorage();

                update_option(self::VERSION_OPTION, JOTFORM_BRIDGE_VERSION, false);
            },
            $networkWide
        );
    }

    /** Nothing to tear down; configuration is never touched on deactivation. */
    public static function onDeactivate(bool $networkWide = false): void
    {
    }

    /**
     * Runs one lifecycle step on every site of a network activation.
     *
     * @param callable(): void $step
     */
    private static function eachSite(callable $step, bool $networkWide): void
    {
        if (!$networkWide || !is_multisite()) {
            $step();

            return;
        }

        foreach (self::siteIds() as $siteId) {
            switch_to_blog($siteId);

            $step();

            restore_current_blog();
        }
    }

    /** Removes storage older versions used; runs on activation and on upgrade. */
    private static function purgeLegacyStorage(): void
    {
        SchemaRepository::purgeLegacyTransients();

        delete_transient(FormRepository::LEGACY_TRANSIENT);

        // Account form list.
        delete_option(FormRepository::LEGACY_OPTION);
        delete_option(FormRepository::LEGACY_META_OPTION);
        delete_option(FormRepository::LEGACY_HIDDEN_OPTION);

        // Template scan cache.
        delete_option('jotform_bridge_templates');

        // Submission tally.
        delete_option('jotform_bridge_stats');
    }

    /**
     * @return array<int, int>
     */
    private static function siteIds(): array
    {
        $sites = get_sites(['fields' => 'ids', 'number' => 0]);

        return is_array($sites) ? array_map('intval', $sites) : [];
    }

    /** Purges legacy storage once per version; synced schemas survive. */
    private static function maybeUpgrade(): void
    {
        $stored = get_option(self::VERSION_OPTION, '');

        if (is_string($stored) && $stored === JOTFORM_BRIDGE_VERSION) {
            return;
        }

        Settings::purgeStoredKey();
        self::purgeLegacyStorage();

        update_option(self::VERSION_OPTION, JOTFORM_BRIDGE_VERSION, false);
    }
}
