<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The cheapest anti-abuse layer: a field a human never sees and a naive bot
 * always fills.
 *
 * It is the first provider shipped for the `jotform_bridge_spam_check`
 * extension point, and it is deliberately written the way a third-party
 * provider would be — a filter callback and a piece of markup, no pipeline
 * changes. Turnstile or reCAPTCHA would slot in exactly here.
 *
 * What it catches: scripts that parse the HTML and post every input they find.
 * What it does not catch: anything driving a real browser engine, which sees
 * the field is off-screen and leaves it alone, and anything that has looked at
 * this form once and now omits the field entirely. A missing value is therefore
 * treated as "no honeypot on this form", not as an attack — a custom template
 * is free not to include one, and refusing those submissions would break every
 * theme that has not been updated. The layers above (rate limiting, the quota
 * guard, and a challenge provider if one is configured) are what cover the rest.
 */
final class Honeypot
{
    /**
     * Key inside the `spam` container of the request body.
     */
    public const KEY = 'hp';

    /**
     * The `name` attribute of the decoy input.
     *
     * Plausible enough that a scanner looking for a website field fills it in,
     * but not one of the tokens a browser's autofill recognises: a password
     * manager writing into the honeypot would reject a real visitor.
     */
    public const FIELD_NAME = 'jfb_website';

    /**
     * The plugin ships no stylesheet, so the field hides itself.
     *
     * Off-screen rather than `display:none` or the `hidden` attribute: part of
     * the bots worth catching skip fields that are hidden the obvious way.
     */
    private const HIDE_STYLE = 'position:absolute!important;left:-9999px!important;'
        . 'top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;';

    /**
     * Keeps the element ID unique when several forms share one page.
     */
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
        // Another provider has already decided; never overrule a rejection.
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

        // The visitor is told nothing about why: returning false yields the
        // generic rejection message, so the response cannot be used to work out
        // which field the trap was.
        return trim((string) $value) === '' ? $allowed : false;
    }

    /**
     * The decoy markup, ready to print.
     *
     * @param string $prefix Element ID prefix, so two forms on one page differ.
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
