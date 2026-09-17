<?php

declare(strict_types=1);

namespace JotformBridge\Updates;

use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tells WordPress when a newer release exists on GitHub.
 *
 * The plugin is not on wordpress.org, so without this every install is a
 * manual ZIP upload and a fleet of sites drifts apart. Core has done the hard
 * half since 5.8: a plugin whose header carries `Update URI` is left alone by
 * the wordpress.org check and, instead, `update_plugins_{hostname}` is asked
 * whether there is anything newer. Answer it with a version and a package URL
 * and the Updates screen, the "update now" link and the installer all work as
 * they do for any other plugin. No library is needed for that.
 *
 * The package is the ZIP the release workflow attached to the GitHub Release —
 * never GitHub's own source archive. The source archive unpacks into
 * `JotForm-Bridge-<sha>/` and carries the whole repository, tests included,
 * which is neither the directory name WordPress expects nor the package
 * AGENTS.md allows to ship. A release with no matching asset is therefore not
 * an update at all.
 *
 * The answer is a site transient with a TTL: cheap state, in the sense of
 * "Storage and caching" in AGENTS.md. The GitHub API allows sixty anonymous
 * requests an hour per address, and `wp_update_plugins()` runs on the plugins
 * screen, on cron and on every admin page load once its own throttle expires,
 * so the answer is kept for twelve hours and a failure for one. "Check again"
 * on the Updates screen clears Core's transient and, through the same action,
 * ours — so a person who asks for a fresh check gets one.
 */
final class GitHubUpdater
{
    /**
     * The repository the releases come from, as `owner/name`.
     */
    public const REPOSITORY = 'suborgerry/JotForm-Bridge';

    /**
     * The host in the `Update URI` header, and therefore the filter suffix.
     */
    public const HOST = 'github.com';

    public const SLUG = 'jotform-bridge';

    public const TRANSIENT = 'jotform_bridge_update_check';

    /**
     * How long a good answer is kept.
     */
    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * How long a failed check is kept before GitHub is asked again. Shorter,
     * because the failure may have been GitHub's, but not zero: a site behind a
     * broken outbound proxy must not pay a ten-second timeout on every admin
     * page load.
     */
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

        // Fired by wp_clean_plugins_cache(): "Check again" on the Updates
        // screen, and the upgrader after it installed something. Both are
        // moments a stale answer would be wrong.
        add_action('delete_site_transient_update_plugins', [$this, 'forget']);
    }

    /**
     * Answers Core's update check for one plugin hosted on github.com.
     *
     * The hostname is the whole of what the filter name says, so every plugin
     * whose `Update URI` points at GitHub arrives here. Only the one naming
     * this repository is ours; the rest pass through untouched, or another
     * plugin would be offered our package.
     *
     * Core compares the version itself and files the answer under `response`
     * or `no_update` accordingly, so the current release is returned whether or
     * not it is newer. The `no_update` entry is what gives the plugin row its
     * "View details" link and its auto-update toggle.
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
     * Without it the link Core renders beside an available update opens
     * wordpress.org's information for a slug that does not exist there.
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
                // The release notes are the changelog section of readme.txt,
                // which is plain text with one item per line. Rendered as such:
                // there is no Markdown parser in Core and none is worth adding
                // for this.
                'changelog' => nl2br(esc_html($release['notes'])),
            ],
        ];
    }

    /**
     * Drops the remembered answer so the next check asks GitHub.
     */
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

        // GitHub treats owner and repository names case-insensitively, and so
        // does the comparison; a trailing slash or ".git" is somebody's habit,
        // not a different repository.
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
            // A stored null is a remembered "nothing to offer"; anything else
            // has to have the shape this version writes, or it is treated as
            // absent — an older version's transient is not worth reading.
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
     * Asks GitHub for the latest release and reads out what the update needs.
     *
     * `/releases/latest` is what makes this safe to point at: GitHub already
     * excludes drafts and pre-releases from it, so a release being written is
     * not offered to anybody.
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

        // No release has been published yet. Not a failure, and not worth
        // asking again within the hour.
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

        // From here on the answer is a real release, and a defect in it is a
        // defect in the release rather than in the connection; it is kept for
        // the full period like any other answer.
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
     * The download URL of the ZIP bin/build-zip.sh built for this version.
     *
     * The name is checked exactly, and so is where the URL points: an asset is
     * uploaded by whoever can write to the repository, but the URL Core will
     * download and unpack into wp-content/plugins/ still has to be a GitHub
     * release asset of this repository and nothing else.
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
