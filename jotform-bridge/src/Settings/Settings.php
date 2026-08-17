<?php

declare(strict_types=1);

namespace JotformBridge\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the plugin settings option.
 *
 * The API key never leaves the server: it is masked on output and the raw value
 * is only handed to JotformClient.
 */
final class Settings
{
    public const OPTION = 'jotform_bridge_settings';

    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_OPTION   = 'option';
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

        return array_merge($this->defaults(), $stored);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'api_key'                  => '',
            'region'                   => self::REGION_STANDARD,
            'base_url'                 => '',
            'debug_logging'            => false,
            'delete_data_on_uninstall' => false,
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
     * Resolves the API key: the JOTFORM_API_KEY constant wins over the option.
     */
    public function apiKey(): string
    {
        if ($this->hasConstantKey()) {
            return (string) constant('JOTFORM_API_KEY');
        }

        $stored = $this->all()['api_key'];

        return is_string($stored) ? $stored : '';
    }

    public function apiKeySource(): string
    {
        if ($this->hasConstantKey()) {
            return self::SOURCE_CONSTANT;
        }

        return $this->apiKey() !== '' ? self::SOURCE_OPTION : self::SOURCE_NONE;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function isApiKeyLocked(): bool
    {
        return $this->hasConstantKey();
    }

    /**
     * Never render the stored key back to the browser; only a hint of it.
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
        $current = $this->all();
        $clean   = $current;

        $region          = sanitize_key(self::scalar($input, 'region'));
        $clean['region'] = array_key_exists($region, self::regions())
            ? $region
            : self::REGION_STANDARD;

        $clean['base_url'] = self::sanitizeBaseUrl(self::scalar($input, 'base_url'));

        $clean['debug_logging']            = !empty($input['debug_logging']);
        $clean['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']);

        // A constant-provided key is never overwritten from the UI.
        if (!$this->hasConstantKey()) {
            $stored           = is_string($current['api_key']) ? $current['api_key'] : '';
            $clean['api_key'] = $this->resolveSubmittedKey($input, $stored);
        }

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
     * An empty field keeps the stored key; "remove" clears it.
     *
     * @param array<string, mixed> $input
     */
    private function resolveSubmittedKey(array $input, string $current): string
    {
        if (!empty($input['remove_api_key'])) {
            return '';
        }

        $submitted = trim(sanitize_text_field(self::scalar($input, 'api_key')));

        if ($submitted === '') {
            return $current;
        }

        return $submitted;
    }

    private function hasConstantKey(): bool
    {
        return defined('JOTFORM_API_KEY') && trim((string) constant('JOTFORM_API_KEY')) !== '';
    }
}
