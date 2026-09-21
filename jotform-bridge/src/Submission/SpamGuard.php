<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/** The spam extension point every submission passes through; providers are filter callbacks. */
final class SpamGuard
{
    public const FILTER = 'jotform_bridge_spam_check';

    /**
     * @param array<string, string|array<int, string>> $values  Sanitized values.
     * @param array<string, mixed>                     $context Request metadata and the `spam` container.
     *
     * @return string Empty when the submission may proceed, otherwise the visitor-facing message.
     */
    public function check(string $integrationSlug, array $values, array $context = []): string
    {
        /**
         * Filters whether a submission is allowed to reach Jotform.
         *
         * Return true to allow, false to reject with the default message, or a
         * string to reject with a custom, visitor-facing message.
         *
         * @param bool|string                              $allowed Allow the submission.
         * @param string                                   $slug    Integration slug.
         * @param array<string, string|array<int, string>> $values  Sanitized values.
         * @param array<string, mixed>                     $context Request metadata.
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is the literal 'jotform_bridge_spam_check'.
        $allowed = apply_filters(self::FILTER, true, $integrationSlug, $values, $context);

        if ($allowed === true) {
            return '';
        }

        if (is_string($allowed) && trim($allowed) !== '') {
            return trim($allowed);
        }

        return __('This submission was rejected. Please try again.', 'jotform-bridge');
    }
}
