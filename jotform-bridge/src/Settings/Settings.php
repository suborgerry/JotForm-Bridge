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
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        // A site upgraded from a version that still kept a key in the option
        // carries one until purgeStoredKey() runs; it is never a key source.
        unset($stored['api_key']);

        return array_merge($this->defaults(), $stored);
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
            'monthly_quota'            => 0,
        ];
    }

    /**
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

        return array_key_exists($region, self::regions()) ? $region : self::REGION_STANDARD;
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

    /**
     * The monthly submission allowance of the Jotform plan, or 0 when unknown.
     *
     * Jotform reports how much of the allowance has been spent, but not what the
     * allowance is, so the number has to be entered once. Without it the quota
     * guard still limits the daily rate — it just cannot tell how close the
     * account is to having its forms switched off.
     */
    public function monthlyQuota(): int
    {
        return max(0, (int) $this->all()['monthly_quota']);
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
        $clean['region'] = array_key_exists($region, self::regions())
            ? $region
            : self::REGION_STANDARD;

        $clean['base_url'] = self::sanitizeBaseUrl(self::scalar($input, 'base_url'));

        $clean['debug_logging']            = !empty($input['debug_logging']);
        $clean['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']);
        $clean['monthly_quota']            = max(0, (int) self::scalar($input, 'monthly_quota'));

        update_option(self::OPTION, $clean);

        return $clean;
    }

    /**
     * The custom base URL is the one setting that decides where the API key is
     * sent, so it is restricted rather than merely escaped: an absolute http(s)
     * URL with a host, and nothing after the path.
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

        update_option(self::OPTION, $stored);
    }

    private function hasConstantKey(): bool
    {
        return defined(self::KEY_CONSTANT) && trim((string) constant(self::KEY_CONSTANT)) !== '';
    }
}
