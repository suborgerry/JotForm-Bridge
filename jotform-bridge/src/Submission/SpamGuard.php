<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The extension point every submission passes through before it reaches Jotform.
 *
 * No provider is implemented here. The point of the class is that adding
 * Turnstile, reCAPTCHA or a custom check later means writing a filter callback,
 * not changing the pipeline: the hook, the context it receives and the place it
 * runs in are already fixed.
 */
final class SpamGuard
{
    public const FILTER = 'jotform_bridge_spam_check';

    /**
     * @param array<string, string|array<int, string>> $values  Sanitized values.
     * @param array<string, mixed>                     $context Request metadata
     *                                                          plus anything a
     *                                                          provider needs,
     *                                                          e.g. a challenge
     *                                                          token.
     *
     * @return string Empty when the submission may proceed, otherwise the
     *                message to show the visitor.
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
