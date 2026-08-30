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
use JotformBridge\Rendering\AutoRenderer;
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

        // Everything below is registered through a closure rather than by
        // handing WordPress a built object. The hooks have to exist on every
        // request; the services behind them are needed on almost none of them —
        // not in the admin, not during cron, not on a REST call belonging to
        // another plugin. Building them anyway would also mean that a fault
        // anywhere in the graph takes down every request on the site, including
        // the admin screens somebody would use to fix it.
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

        // The shipped anti-abuse providers register themselves on the spam
        // extension point, exactly like a third-party one would. Registering is
        // free: a form without the matching markup never triggers them.
        (new Honeypot())->register();
        (new MinimumTime())->register();
        (new ProofOfWork())->register();

        // Registers itself only when the site has configured keys for it.
        (new Turnstile($this->logger))->register();

        (new SubmissionController(static fn(): SubmissionPipeline => self::instance()->pipeline()))->register();

        if (is_admin()) {
            // Only on this plugin's own screens; the class decides.
            (new AdminAssets())->register();

            (new ApiKeyNotice($this->settings))->register();

            // A tripped circuit breaker has to be visible and clearable.
            (new QuotaNotice($this->quota()))->register();

            // Says how to switch the challenge on, once, and how to finish the
            // job if only half of it was done.
            (new TurnstileNotice())->register();

            // Registration order decides the submenu order: Integrations first.
            (new IntegrationsPage(
                $this->integrations(),
                $this->forms(),
                $this->schemas(),
                $this->templates(),
                $this->compatibility(),
                null,
                new TestSubmission($this->schemas(), $this->client(), null, null, $this->quota())
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
            $this->templates = new TemplateRegistry(null, $this->logger);
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
     * The service behind both `jotform_bridge_render()` and the shortcode.
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

    /**
     * The account-wide submission circuit breaker.
     */
    public function quota(): QuotaGuard
    {
        if ($this->quota === null) {
            $this->quota = new QuotaGuard($this->settings);
        }

        return $this->quota;
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
            $this->logger,
            null,
            null,
            $this->quota()
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
     *
     * @param bool $networkWide True when the plugin was activated for a whole
     *                          multisite network, in which case the hook fires
     *                          once rather than once per site.
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

    /**
     * Nothing to tear down: the plugin keeps no derived state that outlives a
     * request, and configuration is never touched on deactivation.
     */
    public static function onDeactivate(bool $networkWide = false): void
    {
    }

    /**
     * Runs one lifecycle step against every site it applies to.
     *
     * Options are per site, and the activation hook fires once for a whole
     * network rather than once per site in it, so a network activation that
     * only touched the current blog would leave every other one carrying state
     * from whatever version was there before.
     *
     * Only activation and deactivation need this. maybeUpgrade() runs on
     * `plugins_loaded`, which happens inside one site's context on every
     * request, so each site upgrades itself the first time it is visited.
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

    /**
     * Removes storage older versions used and this one does not.
     *
     * Runs on activation as well as on the version bump, because "deactivate,
     * update, activate" is how a lot of people upgrade and it must clean up the
     * same things.
     */
    private static function purgeLegacyStorage(): void
    {
        SchemaRepository::purgeLegacyTransients();

        delete_transient(FormRepository::LEGACY_TRANSIENT);

        // Versions up to 0.1.0 cached the template scan. The scan is now read
        // from the theme on demand, so the option is dead weight.
        delete_option('jotform_bridge_templates');

        // The per-integration submission tally was removed with the screens
        // that read it; nothing writes this option any more.
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

    /**
     * Discards derived state after an upgrade.
     *
     * Synced schemas survive: a new version may normalize them differently, but
     * that is reported as "synced by an older version — re-sync recommended" on
     * the integration screen rather than acted on behind the site owner's back.
     * The one thing rewritten here is the settings option: a key stored by a
     * version that still accepted one has to leave the database.
     */
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
