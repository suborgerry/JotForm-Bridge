<?php

declare(strict_types=1);

namespace JotformBridge\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves a configured redirect page ID into an internal URL, or a reason why
 * not. Used by both the admin warning and the submission response; the URL is
 * resolved at answer time and never read from the request.
 */
final class RedirectTarget
{
    /** Redirect is not configured for this integration. */
    public const STATE_DISABLED = 'disabled';

    /** Redirect is configured and the target resolves. */
    public const STATE_OK = 'ok';

    /** Redirect is configured but no page was chosen. */
    public const STATE_UNSET = 'unset';

    /** The chosen page no longer exists. */
    public const STATE_MISSING = 'missing';

    public const STATE_TRASHED = 'trashed';

    /** The page exists but is a draft, pending, private or scheduled. */
    public const STATE_UNPUBLISHED = 'unpublished';

    /** The permalink did not resolve, or resolved off-site. */
    public const STATE_INVALID_URL = 'invalid_url';

    /**
     * `url` is non-empty only in STATE_OK.
     *
     * @return array{state:string, url:string, delay:int, label:string, message:string}
     */
    public function check(Integration $integration): array
    {
        if (!$integration->redirectsOnSuccess()) {
            return $this->state(
                self::STATE_DISABLED,
                __('Success message', 'jotform-bridge'),
                __('This form shows its success message and stays on the page.', 'jotform-bridge')
            );
        }

        $pageId = $integration->redirectPageId();

        if ($pageId <= 0) {
            return $this->state(
                self::STATE_UNSET,
                __('Redirect target missing', 'jotform-bridge'),
                __('Redirect is enabled but no page is selected. The success message is shown instead.', 'jotform-bridge')
            );
        }

        $status = get_post_status($pageId);

        if ($status === false) {
            return $this->state(
                self::STATE_MISSING,
                __('Redirect target deleted', 'jotform-bridge'),
                __('The selected page no longer exists. The success message is shown instead.', 'jotform-bridge')
            );
        }

        if ($status === 'trash') {
            return $this->state(
                self::STATE_TRASHED,
                __('Redirect target in trash', 'jotform-bridge'),
                __('The selected page is in the trash. The success message is shown instead.', 'jotform-bridge')
            );
        }

        if ($status !== 'publish') {
            return $this->state(
                self::STATE_UNPUBLISHED,
                __('Redirect target not published', 'jotform-bridge'),
                __('The selected page is not published. The success message is shown instead.', 'jotform-bridge')
            );
        }

        $url = $this->internalUrl($pageId);

        if ($url === '') {
            return $this->state(
                self::STATE_INVALID_URL,
                __('Redirect target unreachable', 'jotform-bridge'),
                __('The selected page has no usable permalink on this site. The success message is shown instead.', 'jotform-bridge')
            );
        }

        $delay = Integration::clampDelay($integration->redirectDelay());

        return [
            'state'   => self::STATE_OK,
            'url'     => $url,
            'delay'   => $delay,
            'label'   => __('Redirect', 'jotform-bridge'),
            'message' => $delay > 0
                ? sprintf(
                    /* translators: 1: page URL, 2: delay in seconds */
                    __('After a successful submission the visitor is sent to %1$s after %2$d seconds.', 'jotform-bridge'),
                    $url,
                    $delay
                )
                : sprintf(
                    /* translators: %s: page URL */
                    __('After a successful submission the visitor is sent to %s.', 'jotform-bridge'),
                    $url
                ),
        ];
    }

    /**
     * Whether a configured target cannot be served.
     *
     * @param array{state:string, url:string, delay:int, label:string, message:string} $target
     */
    public static function isBroken(array $target): bool
    {
        return $target['state'] !== self::STATE_OK && $target['state'] !== self::STATE_DISABLED;
    }

    /** The permalink of a page, only if it stays on this site. */
    private function internalUrl(int $pageId): string
    {
        $permalink = get_permalink($pageId);

        if (!is_string($permalink) || $permalink === '') {
            return '';
        }

        if (str_starts_with($permalink, '//')) {
            return '';
        }

        $host = wp_parse_url($permalink, PHP_URL_HOST);
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (is_string($host) && is_string($home) && strcasecmp($host, $home) !== 0) {
            return '';
        }

        return (string) wp_validate_redirect($permalink, '');
    }

    /**
     * @return array{state:string, url:string, delay:int, label:string, message:string}
     */
    private function state(string $state, string $label, string $message): array
    {
        return [
            'state'   => $state,
            'url'     => '',
            'delay'   => 0,
            'label'   => $label,
            'message' => $message,
        ];
    }
}
