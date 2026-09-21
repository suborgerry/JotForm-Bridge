<?php

declare(strict_types=1);

namespace JotformBridge\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the plugin settings option. The API key is not part of it:
 * it comes from the JOTFORM_API_KEY constant only.
 */
final class Settings
{
    public const OPTION = 'jotform_bridge_settings';

    /** The only source of the API key. */
    public const KEY_CONSTANT = 'JOTFORM_API_KEY';

    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_NONE     = 'none';

    public const REGION_STANDARD = 'standard';
    public const REGION_EU       = 'eu';
    public const REGION_HIPAA    = 'hipaa';
    public const REGION_CUSTOM   = 'custom';

    /** Hosts a custom base URL may point at (the API key is sent there). */
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
     * Region slugs without labels; usable before the text domain is loaded.
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
     * In-request memo of the option.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /** Generation the memo was taken from. */
    private int $memoGeneration = -1;

    /** Bumped by every write, so every instance's memo is invalidated. */
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

        // Legacy keys from earlier versions; never read.
        unset($stored['api_key'], $stored['monthly_quota']);

        $this->memoGeneration = self::$generation;

        return $this->memo = array_merge($this->defaults(), $stored);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'popular_email_domains_only' => false,
            'allowed_email_domains' => "gmail.com\noutlook.com\nhotmail.com\nlive.com\nmsn.com\nicloud.com\nme.com\nmac.com\nproton.me\nprotonmail.com\nprotonmail.ch\npm.me",
            'region'                   => self::REGION_STANDARD,
            'base_url'                 => '',
            'debug_logging'            => false,
            'delete_data_on_uninstall' => false,
        ];
    }

    public static function isRegion(string $region): bool
    {
        return in_array($region, self::REGION_SLUGS, true);
    }

    /**
     * Region labels for the settings screen; translates, so not usable before init.
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

    /** The API key, or '' when the constant is missing. */
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

    /** The last four characters of the key, masked. */
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

    /** The single place the Jotform API base URL is built. */
    public function baseUrl(): string
    {
        $region = $this->region();

        if ($region === self::REGION_CUSTOM) {
            $stored = $this->all()['base_url'];
            $custom = self::sanitizeBaseUrl(is_string($stored) ? $stored : '');

            if ($custom !== '') {
                return $custom;
            }

            return self::regionUrl(self::REGION_STANDARD);
        }

        return self::regionUrl($region);
    }

    /** The documented base URL for one region. */
    public static function regionUrl(string $region): string
    {
        return self::REGION_URLS[$region] ?? self::REGION_URLS[self::REGION_STANDARD];
    }

    public function debugEnabled(): bool
    {
        return (bool) $this->all()['debug_logging'];
    }

    public function deletesDataOnUninstall(): bool
    {
        return (bool) $this->all()['delete_data_on_uninstall'];
    }

    public function popularEmailDomainsOnly(): bool
    {
        return $this->all()['popular_email_domains_only'] === true;
    }

    /** @return array<int, string> */
    public function allowedEmailDomains(): array
    {
        $raw = $this->all()['allowed_email_domains'];

        return self::normalizeEmailDomains(is_string($raw) ? $raw : '');
    }

    /** @return array<int, string> */
    private static function normalizeEmailDomains(string $raw): array
    {
        $domains = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $domain = strtolower(trim($line));
            if (strlen($domain) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain)) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
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
        $clean = $this->all();

        if (isset($input['validation_tab'])) {
            $clean['popular_email_domains_only'] = !empty($input['popular_email_domains_only']);
            $clean['allowed_email_domains'] = implode("\n", self::normalizeEmailDomains(self::scalar($input, 'allowed_email_domains')));
        } else {
            $region          = sanitize_key(self::scalar($input, 'region'));
            $clean['region'] = self::isRegion($region) ? $region : self::REGION_STANDARD;

            $clean['base_url'] = self::sanitizeBaseUrl(self::scalar($input, 'base_url'));

            $clean['debug_logging']            = !empty($input['debug_logging']);
            $clean['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']);
        }

        self::$generation++;

        update_option(self::OPTION, $clean);

        return $clean;
    }

    /** An absolute http(s) URL on an allowed host, with nothing after the path. */
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

        // Credentials, query and fragment are dropped.
        $rebuilt = $scheme . '://' . strtolower((string) $parts['host']);

        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }

        if (isset($parts['path'])) {
            $rebuilt .= (string) $parts['path'];
        }

        return untrailingslashit($rebuilt);
    }

    /** Whether a host may receive the API key; an entry matches itself and its subdomains. */
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
     * One scalar value out of a raw request slice; arrays are ignored.
     *
     * @param array<string, mixed> $input
     */
    private static function scalar(array $input, string $key): string
    {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
    }

    /** Removes a key left in the option by an earlier version; runs on upgrade. */
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
