<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Honeypot: a field a human never sees and a naive bot fills. A missing value
 * means the form carries no honeypot and is allowed.
 */
final class Honeypot
{
    /** Key inside the `spam` container. */
    public const KEY = 'hp';

    /** Decoy `name`: plausible to a scanner, not an autofill token. */
    public const FIELD_NAME = 'jfb_website';

    /** Off-screen rather than `display:none`, which some bots detect. */
    private const HIDE_STYLE = 'position:absolute!important;left:-9999px!important;'
        . 'top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;';

    /** Keeps the element ID unique across several forms on one page. */
    private static int $instances = 0;

    public function register(): void
    {
        add_filter(SpamGuard::FILTER, [$this, 'check'], 10, 4);
    }

    /**
     * @param bool|string                              $allowed Verdict so far.
     * @param array<string, string|array<int, string>> $values  Sanitized values.
     * @param array<string, mixed>                     $context Request metadata.
     *
     * @return bool|string False rejects with the default, visitor-facing message.
     */
    public function check($allowed, string $slug, array $values, array $context)
    {
        if ($allowed !== true) {
            return $allowed;
        }

        $spam = isset($context['spam']) && is_array($context['spam']) ? $context['spam'] : [];

        if (!array_key_exists(self::KEY, $spam)) {
            return $allowed;
        }

        $value = $spam[self::KEY];

        if (!is_scalar($value)) {
            return false;
        }

        return trim((string) $value) === '' ? $allowed : false;
    }

    /**
     * @param string $prefix Element ID prefix.
     */
    public static function markup(string $prefix = 'jfb'): string
    {
        $id = sprintf('%s-hp-%d', $prefix !== '' ? $prefix : 'jfb', ++self::$instances);

        return sprintf(
            '<div class="jfb-hp" aria-hidden="true" style="%1$s">'
            . '<label for="%2$s">%3$s</label>'
            . '<input type="text" id="%2$s" name="%4$s" value="" data-jotform-spam="%5$s"'
            . ' tabindex="-1" autocomplete="off">'
            . '</div>',
            esc_attr(self::HIDE_STYLE),
            esc_attr($id),
            esc_html__('Leave this field empty.', 'jotform-bridge'),
            esc_attr(self::FIELD_NAME),
            esc_attr(self::KEY)
        );
    }
}
