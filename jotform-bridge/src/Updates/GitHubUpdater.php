<?php

declare(strict_types=1);

namespace JotformBridge\Updates;

use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Answers Core's `update_plugins_github.com` check from the latest GitHub
 * Release. The package is the `jotform-bridge-{version}.zip` asset attached to
 * the release, never the source archive. The answer is kept in a site
 * transient (CACHE_TTL, RETRY_TTL on failure) and dropped on "Check again".
 */
final class GitHubUpdater
{
    /** The repository the releases come from, as `owner/name`. */
    public const REPOSITORY = 'suborgerry/JotForm-Bridge';

    /** The host in the `Update URI` header, and therefore the filter suffix. */
    public const HOST = 'github.com';

    public const SLUG = 'jotform-bridge';

    public const TRANSIENT = 'jotform_bridge_update_check';

    /** How long a good answer is kept. */
    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** How long a failed check is kept before GitHub is asked again. */
    public const RETRY_TTL = HOUR_IN_SECONDS;

    private const API = 'https://api.github.com/repos/%s/releases/latest';

    private const TIMEOUT = 10;

    private Logger $logger;

    private string $repository;

    public function __construct(Logger $logger, string $repository = self::REPOSITORY)
    {
        $this->logger     = $logger;
        $this->repository = $repository;
    }

    public function register(): void
    {
        add_filter('update_plugins_' . self::HOST, [$this, 'check'], 10, 3);
        add_filter('plugins_api', [$this, 'information'], 10, 3);

        // Fired by wp_clean_plugins_cache(): the upgrader and WP-CLI.
        add_action('delete_site_transient_update_plugins', [$this, 'forget']);

        // Before Core's wp_update_plugins() on the same action, at 10.
        add_action('load-update-core.php', [$this, 'forgetOnForcedCheck'], 9);
    }

    /** Drops the remembered answer on "Check again", before Core's own check runs. */
    public function forgetOnForcedCheck(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own nonce-less parameter; only a cache is cleared.
        if (!empty($_GET['force-check'])) {
            $this->forget();
        }
    }

    /**
     * Answers Core's update check. The filter is shared by every plugin hosted
     * on github.com, so anything not naming this repository passes through.
     * Core compares the version itself, so the current release is returned
     * whether or not it is newer.
     *
     * @param array<string, mixed>|false $update     What an earlier callback answered.
     * @param array<string, mixed>       $pluginData The parsed plugin header.
     *
     * @return array<string, mixed>|false
     */
    public function check($update, array $pluginData, string $pluginFile)
    {
        if (!$this->isOurs($pluginData)) {
            return $update;
        }

        $release = $this->release();

        if ($release === null) {
            return $update;
        }

        return [
            'id'           => (string) $pluginData['UpdateURI'],
            'slug'         => self::SLUG,
            'plugin'       => $pluginFile,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => JOTFORM_BRIDGE_MIN_WP,
            'requires_php' => JOTFORM_BRIDGE_MIN_PHP,
        ];
    }

    /**
     * Answers the "View details" window for this plugin.
     *
     * @param object|array<string, mixed>|false $result What an earlier callback answered.
     * @param object|array<string, mixed>       $args   The request; `slug` is what matters.
     *
     * @return object|array<string, mixed>|false
     */
    public function information($result, string $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $slug = is_object($args) ? ($args->slug ?? null) : ($args['slug'] ?? null);

        if ($slug !== self::SLUG) {
            return $result;
        }

        $release = $this->release();

        if ($release === null) {
            return $result;
        }

        return (object) [
            'name'          => 'Jotform Bridge',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'homepage'      => 'https://' . self::HOST . '/' . $this->repository,
            'download_link' => $release['package'],
            'requires'      => JOTFORM_BRIDGE_MIN_WP,
            'requires_php'  => JOTFORM_BRIDGE_MIN_PHP,
            'last_updated'  => $release['published'],
            'sections'      => [
                // Plain text, one item per line.
                'changelog' => nl2br(esc_html($release['notes'])),
            ],
        ];
    }

    /** Drops the remembered answer so the next check asks GitHub. */
    public function forget(): void
    {
        delete_site_transient(self::TRANSIENT);
    }

    /**
     * Whether a plugin header names this repository.
     *
     * @param array<string, mixed> $pluginData
     */
    private function isOurs(array $pluginData): bool
    {
        $uri = isset($pluginData['UpdateURI']) ? (string) $pluginData['UpdateURI'] : '';

        if ($uri === '') {
            return false;
        }

        $host = wp_parse_url($uri, PHP_URL_HOST);
        $path = wp_parse_url($uri, PHP_URL_PATH);

        if (!is_string($host) || !is_string($path)) {
            return false;
        }

        // Case-insensitive; a trailing slash or ".git" is ignored.
        $path = preg_replace('#(\.git)?/*$#', '', $path) ?? $path;

        return strtolower($host) === self::HOST
            && strcasecmp(ltrim($path, '/'), $this->repository) === 0;
    }

    /**
     * The latest release, from the transient or from GitHub.
     *
     * @return array{version: string, url: string, package: string, notes: string, published: string}|null
     */
    private function release(): ?array
    {
        $cached = get_site_transient(self::TRANSIENT);

        if (is_array($cached) && array_key_exists('release', $cached)) {
            // A stored null is a remembered "nothing to offer".
            return $this->isRelease($cached['release']) ? $cached['release'] : null;
        }

        ['release' => $release, 'ttl' => $ttl] = $this->fetch();

        set_site_transient(self::TRANSIENT, ['release' => $release, 'checked' => time()], $ttl);

        return $release;
    }

    /**
     * @param mixed $value
     *
     * @phpstan-assert-if-true array{version: string, url: string, package: string, notes: string, published: string} $value
     */
    private function isRelease($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach (['version', 'url', 'package', 'notes', 'published'] as $key) {
            if (!isset($value[$key]) || !is_string($value[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Asks GitHub for the latest release (drafts and pre-releases excluded).
     *
     * @return array{release: array{version: string, url: string, package: string, notes: string, published: string}|null, ttl: int}
     */
    private function fetch(): array
    {
        $response = wp_remote_get(
            sprintf(self::API, $this->repository),
            [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]
        );

        if (is_wp_error($response)) {
            $this->logger->error('The update check could not reach GitHub.', [
                'error' => $response->get_error_code(),
            ]);

            return ['release' => null, 'ttl' => self::RETRY_TTL];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        // No release published yet; not a failure.
        if ($status === 404) {
            return ['release' => null, 'ttl' => self::CACHE_TTL];
        }

        if ($status !== 200) {
            $this->logger->error('The update check was refused by GitHub.', [
                'status' => $status,
            ]);

            return ['release' => null, 'ttl' => self::RETRY_TTL];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($body) || !isset($body['tag_name']) || !is_string($body['tag_name'])) {
            $this->logger->error('The update check returned an unusable answer.');

            return ['release' => null, 'ttl' => self::RETRY_TTL];
        }

        $version = ltrim($body['tag_name'], 'vV');

        // A defective release is kept for the full period like any other answer.
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $this->logger->error('The latest release is not tagged with a version.', [
                'tag' => $body['tag_name'],
            ]);

            return ['release' => null, 'ttl' => self::CACHE_TTL];
        }

        $package = $this->packageUrl($body, $version);

        if ($package === null) {
            $this->logger->error('The latest release carries no plugin package.', [
                'tag' => $body['tag_name'],
            ]);

            return ['release' => null, 'ttl' => self::CACHE_TTL];
        }

        $url = isset($body['html_url']) && is_string($body['html_url']) ? esc_url_raw($body['html_url'], ['https']) : '';

        return [
            'release' => [
                'version'   => $version,
                'url'       => $url !== '' ? $url : 'https://' . self::HOST . '/' . $this->repository . '/releases',
                'package'   => $package,
                'notes'     => isset($body['body']) && is_string($body['body']) ? $body['body'] : '',
                'published' => isset($body['published_at']) && is_string($body['published_at']) ? $body['published_at'] : '',
            ],
            'ttl'     => self::CACHE_TTL,
        ];
    }

    /**
     * The download URL of the built ZIP: exact asset name, under this
     * repository's release downloads.
     *
     * @param array<string, mixed> $release
     */
    private function packageUrl(array $release, string $version): ?string
    {
        if (!isset($release['assets']) || !is_array($release['assets'])) {
            return null;
        }

        $expected = sprintf('%s-%s.zip', self::SLUG, $version);
        $prefix   = sprintf('https://%s/%s/releases/download/', self::HOST, $this->repository);

        foreach ($release['assets'] as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== $expected) {
                continue;
            }

            $url = isset($asset['browser_download_url']) ? esc_url_raw((string) $asset['browser_download_url'], ['https']) : '';

            if ($url !== '' && strncasecmp($url, $prefix, strlen($prefix)) === 0) {
                return $url;
            }
        }

        return null;
    }
}
