<?php

declare(strict_types=1);

namespace JotformBridge\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the plugin settings option.
 *
 * The API key is deliberately not part of that option: it comes from the
 * JOTFORM_API_KEY constant and from nowhere else, so it never reaches the
 * database. It is masked on output and the raw value is only handed to
 * JotformClient.
 */
final class Settings
{
    public const OPTION = 'jotform_bridge_settings';

    /**
     * The only place the plugin ever reads an API key from.
     */
    public const KEY_CONSTANT = 'JOTFORM_API_KEY';

    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_NONE     = 'none';

    public const REGION_STANDARD = 'standard';
    public const REGION_EU       = 'eu';
    public const REGION_HIPAA    = 'hipaa';
    public const REGION_CUSTOM   = 'custom';

    /**
     * Hosts a custom base URL may point at.
     *
     * The custom region exists for Jotform deployments the plugin does not know
     * the address of — a new region, an enterprise host. It is not a general
     * "send my API key wherever" setting, and left unrestricted that is exactly
     * what it was: one settings save, and every request carries the key to
     * somebody else's server. An administrator who genuinely needs another host
     * can add it in code, where the decision is visible and reviewable.
     */
    private const DEFAULT_ALLOWED_HOSTS = ['jotform.com'];

    /**
     * Base URLs documented by Jotform (https://api.jotform.com/docs/).
     *
     * @var array<string, string>
     */
    private const REGION_URLS = [
        self::REGION_STANDARD => 'https://api.jotform.com',
        self::REGION_EU       => 'https://eu-api.jotform.com',
        self::REGION_HIPAA    => 'https://hipaa-api.jotform.com',
    ];

    /**
     * The region slugs, with no labels attached.
     *
     * Separate from regions() on purpose. Validating a stored value must not
     * need a translated string: the settings are read while the plugin boots,
     * on plugins_loaded, and asking for a label there makes WordPress load the
     * text domain before init — which since WordPress 6.7 is a
     * _doing_it_wrong() notice on every admin request, and a translation that
     * is not applied anyway.
     *
     * @var array<int, string>
     */
    private const REGION_SLUGS = [
        self::REGION_STANDARD,
        self::REGION_EU,
        self::REGION_HIPAA,
        self::REGION_CUSTOM,
    ];

    /**
     * In-request memo. Six methods on this class read the option, and Logger
     * asks whether debugging is on for every line it writes.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /**
     * Which generation of the option the memo was taken from.
     */
    private int $memoGeneration = -1;

    /**
     * Bumped by every write, including the static one.
     *
     * purgeStoredKey() is static, so it can change the option without any
     * instance knowing. Today that only happens during upgrade, before anything
     * has read the settings — but relying on that would make correctness a
     * property of the call order rather than of this class. A counter every
     * instance checks costs three lines and does not care about ordering.
     */
    private static int $generation = 0;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null && $this->memoGeneration === self::$generation) {
            return $this->memo;
        }

        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        // A site upgraded from a version that still kept a key in the option
        // carries one until purgeStoredKey() runs; it is never a key source.
        unset($stored['api_key']);

        // Written by versions that tracked the account's monthly allowance.
        // The plugin no longer does, so the value is not carried forward.
        unset($stored['monthly_quota']);

        $this->memoGeneration = self::$generation;

        return $this->memo = array_merge($this->defaults(), $stored);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'region'                   => self::REGION_STANDARD,
            'base_url'                 => '',
            'debug_logging'            => false,
            'delete_data_on_uninstall' => false,
        ];
    }

    /**
     * Whether a string names a region the plugin knows.
     */
    public static function isRegion(string $region): bool
    {
        return in_array($region, self::REGION_SLUGS, true);
    }

    /**
     * The labels for the settings screen. Nothing but a view may call this: it
     * translates, and so cannot be used before init.
     *
     * @return array<string, string> Region slug => human readable label.
     */
    public static function regions(): array
    {
        return [
            self::REGION_STANDARD => __('Standard (api.jotform.com)', 'jotform-bridge'),
            self::REGION_EU       => __('EU (eu-api.jotform.com)', 'jotform-bridge'),
            self::REGION_HIPAA    => __('HIPAA (hipaa-api.jotform.com)', 'jotform-bridge'),
            self::REGION_CUSTOM   => __('Custom base URL', 'jotform-bridge'),
        ];
    }

    /**
     * The configured API key, or an empty string when the constant is missing.
     */
    public function apiKey(): string
    {
        if (!$this->hasConstantKey()) {
            return '';
        }

        return trim((string) constant(self::KEY_CONSTANT));
    }

    public function apiKeySource(): string
    {
        return $this->hasConstantKey() ? self::SOURCE_CONSTANT : self::SOURCE_NONE;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Never render the key back to the browser; only a hint of it.
     */
    public function maskedApiKey(): string
    {
        $key = $this->apiKey();

        if ($key === '') {
            return '';
        }

        $tail = substr($key, -4);

        return str_repeat('*', 8) . $tail;
    }

    public function region(): string
    {
        $stored = $this->all()['region'];
        $region = is_string($stored) ? $stored : '';

        return self::isRegion($region) ? $region : self::REGION_STANDARD;
    }

    /**
     * Single place where the Jotform API base URL is built.
     */
    public function baseUrl(): string
    {
        $region = $this->region();

        if ($region === self::REGION_CUSTOM) {
            $stored = $this->all()['base_url'];
            $custom = self::sanitizeBaseUrl(is_string($stored) ? $stored : '');

            if ($custom !== '') {
                return $custom;
            }

            return self::REGION_URLS[self::REGION_STANDARD];
        }

        return self::REGION_URLS[$region];
    }

    public function debugEnabled(): bool
    {
        return (bool) $this->all()['debug_logging'];
    }

    /**
     * Whether uninstalling may delete the integrations and the settings too.
     */
    public function deletesDataOnUninstall(): bool
    {
        return (bool) $this->all()['delete_data_on_uninstall'];
    }

    /**
     * Sanitizes raw admin input and persists it.
     *
     * @param array<string, mixed> $input Raw $_POST slice.
     *
     * @return array<string, mixed> The stored settings.
     */
    public function save(array $input): array
    {
        // all() has already dropped any legacy key, so nothing carried over
        // here can put one back into the option.
        $clean = $this->all();

        $region          = sanitize_key(self::scalar($input, 'region'));
        $clean['region'] = self::isRegion($region) ? $region : self::REGION_STANDARD;

        $clean['base_url'] = self::sanitizeBaseUrl(self::scalar($input, 'base_url'));

        $clean['debug_logging']            = !empty($input['debug_logging']);
        $clean['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']);

        self::$generation++;

        update_option(self::OPTION, $clean);

        return $clean;
    }

    /**
     * The custom base URL is the one setting that decides where the API key is
     * sent, so it is restricted rather than merely escaped: an absolute http(s)
     * URL, on an allowed host, and nothing after the path.
     */
    public static function sanitizeBaseUrl(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $url   = esc_url_raw($raw, ['http', 'https']);
        $parts = $url !== '' ? wp_parse_url($url) : false;

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';

        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        if (!self::isAllowedHost(strtolower((string) $parts['host']))) {
            return '';
        }

        // Credentials, query and fragment have no meaning for an API base URL
        // and would only travel along with every request.
        $rebuilt = $scheme . '://' . strtolower((string) $parts['host']);

        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }

        if (isset($parts['path'])) {
            $rebuilt .= (string) $parts['path'];
        }

        return untrailingslashit($rebuilt);
    }

    /**
     * Whether a host may receive the API key.
     *
     * An entry matches the host itself and any subdomain of it, and nothing
     * else: "notjotform.com" must not pass because it ends with the same
     * letters.
     */
    public static function isAllowedHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));

        if ($host === '') {
            return false;
        }

        /**
         * Filters the hosts a custom Jotform API base URL may point at.
         *
         * Each entry matches that host and its subdomains. Adding one means
         * accepting that the API key will be sent there.
         *
         * @param array<int, string> $hosts Allowed hosts.
         */
        $allowed = apply_filters('jotform_bridge_allowed_api_hosts', self::DEFAULT_ALLOWED_HOSTS);

        if (!is_array($allowed)) {
            $allowed = self::DEFAULT_ALLOWED_HOSTS;
        }

        foreach ($allowed as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = strtolower(trim($candidate, " \t\n\r\0\x0B."));

            if ($candidate === '') {
                continue;
            }

            if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads one value out of a raw request slice, ignoring arrays and objects.
     *
     * A `settings[region][]=x` request must not become the string "Array": it is
     * simply not a value this form can carry.
     *
     * @param array<string, mixed> $input
     */
    private static function scalar(array $input, string $key): string
    {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
    }

    /**
     * Removes a key left in the option by an earlier version of the plugin.
     *
     * Runs on upgrade rather than on read: a key that once reached the database
     * has to be taken out of it, not merely ignored on the way back.
     */
    public static function purgeStoredKey(): void
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored) || !array_key_exists('api_key', $stored)) {
            return;
        }

        unset($stored['api_key']);

        self::$generation++;

        update_option(self::OPTION, $stored);
    }

    private function hasConstantKey(): bool
    {
        return defined(self::KEY_CONSTANT) && trim((string) constant(self::KEY_CONSTANT)) !== '';
    }
}
