<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Forms\FieldNormalizer;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Integrations\Integration;
use JotformBridge\Submission\Guards\Honeypot;
use JotformBridge\Submission\Guards\Turnstile;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a form directly from the Normalized Schema.
 *
 * This is the fallback renderer: it exists so an integration can go live before
 * anybody has written a theme template, not so it can compete with one. The
 * markup is therefore deliberately plain — semantic elements, predictable
 * classes, no layout opinions and no CSS shipped with it — and it speaks exactly
 * the same `data-jotform-*` contract the frontend script and the custom
 * templates already use.
 *
 * Fields the plugin cannot map are skipped rather than half-rendered: a visitor
 * must never fill in an input whose value would be dropped on the way to
 * Jotform. The admin screen reports those fields separately.
 */
final class AutoRenderer
{
    /**
     * Filter name for replacing or wrapping the markup of a single field.
     */
    public const FILTER_FIELD_HTML = 'jotform_bridge_auto_field_html';

    /**
     * Distinguishes several forms on one page, so element IDs stay unique even
     * when the same integration is rendered twice. One renderer serves the whole
     * request, which is what makes the counter meaningful.
     */
    private int $instances = 0;

    /**
     * Hides text visually while leaving it in the accessibility tree.
     *
     * The same declarations WordPress core uses for `.screen-reader-text`,
     * written inline because the plugin ships no stylesheet of its own — see
     * `requiredMark()`.
     */
    private const SR_ONLY_STYLE = 'position:absolute;width:1px;height:1px;'
        . 'margin:-1px;padding:0;border:0;overflow:hidden;clip-path:inset(50%);'
        . 'clip:rect(1px,1px,1px,1px);white-space:nowrap;';

    /**
     * Autocomplete tokens for the sub-inputs of composite fields.
     *
     * @var array<string, string>
     */
    private const CHILD_AUTOCOMPLETE = [
        'prefix'     => 'honorific-prefix',
        'first'      => 'given-name',
        'middle'     => 'additional-name',
        'last'       => 'family-name',
        'suffix'     => 'honorific-suffix',
        'addr_line1' => 'address-line1',
        'addr_line2' => 'address-line2',
        'city'       => 'address-level2',
        'state'      => 'address-level1',
        'postal'     => 'postal-code',
        'country'    => 'country-name',
    ];

    /**
     * Autocomplete tokens for scalar fields, by normalized type.
     *
     * @var array<string, string>
     */
    private const TYPE_AUTOCOMPLETE = [
        FieldNormalizer::TYPE_EMAIL => 'email',
        FieldNormalizer::TYPE_PHONE => 'tel',
    ];

    /**
     * Input types for scalar fields, by normalized type.
     *
     * @var array<string, string>
     */
    private const TYPE_INPUT = [
        FieldNormalizer::TYPE_TEXT   => 'text',
        FieldNormalizer::TYPE_EMAIL  => 'email',
        FieldNormalizer::TYPE_PHONE  => 'tel',
        FieldNormalizer::TYPE_NUMBER => 'number',
    ];

    public function render(Integration $integration, FormSchema $schema, string $endpoint): string
    {
        $fields = $schema->supportedFields();

        if ($fields === []) {
            return '';
        }

        $slug   = $integration->slug();
        $prefix = sprintf('jfb-%s-%d', $slug !== '' ? $slug : 'form', ++$this->instances);
        $parts  = [];

        foreach ($fields as $field) {
            $html = $field['children'] !== []
                ? $this->composite($field, $prefix)
                : $this->scalar($field, $prefix);

            if ($html === '') {
                continue;
            }

            /**
             * Filters the markup of one automatically rendered field.
             *
             * The intended use is adding a wrapper or a description without
             * taking over the whole form. The returned string is printed as is,
             * which makes this the one place where the plugin hands the output
             * over to somebody else's code.
             *
             * `$html` is finished, escaped markup. `$field` is not: it is the
             * normalized schema entry, and its `label` and `options[*].label`
             * are text as Jotform reports it, which may legitimately contain a
             * quote or an angle bracket. Anything taken out of `$field` and put
             * into markup has to be escaped by the callback:
             *
             *     add_filter(
             *         'jotform_bridge_auto_field_html',
             *         static function (string $html, array $field): string {
             *             return '<div class="col">' . $html
             *                 . '<p>' . esc_html($field['label']) . '</p></div>';
             *         },
             *         10,
             *         2
             *     );
             *
             * The values in `$field` are deliberately left raw, because every
             * other consumer escapes them at the moment it prints them — see
             * `Templates\TemplateScaffold::text()`, which does the same job for
             * the generated starter template. Escaping them here instead would
             * mean the same array key held escaped text in one context and raw
             * text in every other.
             *
             * @param string               $html        Escaped field markup.
             * @param array<string, mixed> $field       Normalized field; its text is raw.
             * @param string               $integration Integration slug.
             */
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER_FIELD_HTML is the literal 'jotform_bridge_auto_field_html'.
            $parts[] = (string) apply_filters(self::FILTER_FIELD_HTML, $html, $field, $slug);
        }

        if ($parts === []) {
            return '';
        }

        return sprintf(
            '<form class="jfb-form" method="post" action="%1$s" data-jotform-bridge data-jotform-integration="%2$s">'
            . '%3$s'
            . '<p class="jfb-success" data-jotform-success role="status" aria-live="polite"></p>'
            . '<div class="jfb-error jfb-error--form" data-jotform-errors role="alert" aria-live="assertive"></div>'
            . '%4$s'
            . '%5$s'
            . '<div class="jfb-actions"><button type="submit" class="jfb-submit">%6$s</button></div>'
            . '</form>',
            esc_url($endpoint),
            esc_attr($slug),
            self::noscript(),
            implode('', $parts),
            // Every automatically rendered form carries the decoy, and the
            // challenge widget when one is configured. A custom template decides
            // for itself, through $honeypot and $turnstile in its context.
            Honeypot::markup($prefix) . Turnstile::markup(),
            esc_html__('Submit', 'jotform-bridge')
        );
    }

    /**
     * What a visitor without JavaScript is told.
     *
     * The form is sent by the plugin's script: the inputs carry semantic
     * identifiers rather than `name` attributes, so a browser submitting this
     * form on its own posts an empty body to the REST endpoint and lands on a
     * JSON error page. Saying so first is the difference between a form that
     * cannot work here and a form that looks broken.
     *
     * Public so a custom template can print the same line — it is in the
     * rendering context as `$noscript`.
     */
    public static function noscript(): string
    {
        return sprintf(
            '<noscript><p class="jfb-noscript">%s</p></noscript>',
            esc_html__(
                'This form needs JavaScript to be sent. Please enable it and reload the page.',
                'jotform-bridge'
            )
        );
    }

    /**
     * One field that maps to a single semantic path.
     *
     * @param array<string, mixed> $field
     */
    private function scalar(array $field, string $prefix): string
    {
        $type = (string) $field['type'];
        $key  = (string) $field['key'];

        if ($type === FieldNormalizer::TYPE_RADIO || $type === FieldNormalizer::TYPE_CHECKBOX) {
            return $this->choiceGroup($field, $prefix);
        }

        $id       = $this->id($prefix, $key);
        $required = (bool) $field['required'];

        if ($type === FieldNormalizer::TYPE_TEXTAREA) {
            $control = sprintf(
                '<textarea class="jfb-input" id="%1$s" data-jotform-field="%2$s"%3$s></textarea>',
                esc_attr($id),
                esc_attr($key),
                $this->controlAttributes($id, $required)
            );
        } elseif ($type === FieldNormalizer::TYPE_SELECT) {
            $control = sprintf(
                '<select class="jfb-input" id="%1$s" data-jotform-field="%2$s"%3$s>%4$s</select>',
                esc_attr($id),
                esc_attr($key),
                $this->controlAttributes($id, $required),
                $this->options($field, $required)
            );
        } else {
            $control = sprintf(
                '<input class="jfb-input" type="%1$s" id="%2$s" data-jotform-field="%3$s"%4$s%5$s%6$s>',
                esc_attr(self::TYPE_INPUT[$type] ?? 'text'),
                esc_attr($id),
                esc_attr($key),
                $this->controlAttributes($id, $required),
                $this->autocomplete(self::TYPE_AUTOCOMPLETE[$type] ?? ''),
                $this->numberRange($field)
            );
        }

        return sprintf(
            '<div class="jfb-field jfb-field--%1$s">%2$s%3$s%4$s</div>',
            esc_attr($type),
            $this->label($id, $this->labelFor($field), $required),
            $control,
            $this->errorSlot($id, $key)
        );
    }

    /**
     * A radio or checkbox group: several inputs sharing one semantic path.
     *
     * @param array<string, mixed> $field
     */
    private function choiceGroup(array $field, string $prefix): string
    {
        if ($field['options'] === []) {
            return '';
        }

        $type     = (string) $field['type'];
        $key      = (string) $field['key'];
        $id       = $this->id($prefix, $key);
        $required = (bool) $field['required'];
        $choices  = '';
        $index    = 0;

        foreach ($field['options'] as $option) {
            $optionId = $id . '-' . ++$index;

            $choices .= sprintf(
                '<label class="jfb-choice" for="%1$s">'
                . '<input type="%2$s" id="%1$s" name="%3$s" value="%4$s" data-jotform-field="%5$s"'
                . ' aria-describedby="%6$s"%7$s>'
                . '<span class="jfb-choice-label">%8$s</span>'
                . '</label>',
                esc_attr($optionId),
                esc_attr($type === FieldNormalizer::TYPE_RADIO ? 'radio' : 'checkbox'),
                esc_attr($id),
                esc_attr((string) $option['value']),
                esc_attr($key),
                // Every input of the group describes itself by the one error
                // slot below it. Without this the server's message is written
                // into an element nothing points at: a screen reader announces
                // the group as invalid and never says why, which was the whole
                // point of sending a message per field.
                esc_attr($id . '-error'),
                // A required radio group is satisfied by any one of its inputs,
                // so the attribute belongs on each of them. A required checkbox
                // group is not: `required` there would demand every single box,
                // so the requirement is left to the server.
                $required && $type === FieldNormalizer::TYPE_RADIO ? ' required' : '',
                esc_html((string) $option['label'])
            );
        }

        // No `aria-required` on the fieldset: it maps to the `group` role,
        // which does not support the attribute, so it is invalid ARIA and is
        // ignored. The requirement is already carried by the legend, which
        // states it in text, and by the `required` attribute on each radio.
        return sprintf(
            '<fieldset class="jfb-field jfb-field--%1$s">%2$s%3$s%4$s</fieldset>',
            esc_attr($type),
            $this->legend($this->labelFor($field), $required),
            $choices,
            $this->errorSlot($id, $key)
        );
    }

    /**
     * A composite field: the parent is a grouping only, the children are the
     * inputs and each of them carries its own semantic path.
     *
     * @param array<string, mixed> $field
     */
    private function composite(array $field, string $prefix): string
    {
        $inner = '';

        foreach ($field['children'] as $child) {
            $key      = (string) $child['key'];
            $id       = $this->id($prefix, $key);
            $required = (bool) $child['required'];
            $label    = (string) $child['label'] !== ''
                ? (string) $child['label']
                : $this->fallbackLabel((string) $child['child']);

            $inner .= sprintf(
                '<div class="jfb-field jfb-field--%1$s">%2$s'
                . '<input class="jfb-input" type="text" id="%3$s" data-jotform-field="%4$s"%5$s%6$s>'
                . '%7$s</div>',
                esc_attr(str_replace('_', '-', (string) $child['child'])),
                $this->label($id, $label, $required),
                esc_attr($id),
                esc_attr($key),
                $this->controlAttributes($id, $required),
                $this->autocomplete(self::CHILD_AUTOCOMPLETE[(string) $child['child']] ?? ''),
                $this->errorSlot($id, $key)
            );
        }

        if ($inner === '') {
            return '';
        }

        return sprintf(
            '<fieldset class="jfb-field jfb-field--%1$s">%2$s%3$s</fieldset>',
            esc_attr((string) $field['type']),
            $this->legend($this->labelFor($field), (bool) $field['required']),
            $inner
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function options(array $field, bool $required): string
    {
        // An empty first option is what makes a required select enforceable and
        // keeps the browser from pre-selecting an answer nobody gave.
        $html = sprintf(
            '<option value=""%1$s>%2$s</option>',
            $required ? ' disabled selected' : ' selected',
            esc_html__('— Select —', 'jotform-bridge')
        );

        foreach ($field['options'] as $option) {
            $html .= sprintf(
                '<option value="%1$s">%2$s</option>',
                esc_attr((string) $option['value']),
                esc_html((string) $option['label'])
            );
        }

        return $html;
    }

    private function label(string $id, string $text, bool $required): string
    {
        return sprintf(
            '<label class="jfb-label" for="%1$s">%2$s%3$s</label>',
            esc_attr($id),
            esc_html($text),
            $this->requiredMark($required)
        );
    }

    private function legend(string $text, bool $required): string
    {
        return sprintf(
            '<legend class="jfb-legend">%1$s%2$s</legend>',
            esc_html($text),
            $this->requiredMark($required)
        );
    }

    private function requiredMark(bool $required): string
    {
        if (!$required) {
            return '';
        }

        // The class is kept so a theme that already styles `.screen-reader-text`
        // keeps control, and the same rules are repeated inline because nothing
        // guarantees the class exists: the plugin ships no frontend stylesheet,
        // and the definition a site usually gets comes from core's block
        // library CSS, which themes and performance plugins routinely remove.
        // Without it this text is not hidden but printed, and every required
        // label reads "First Name *(required)".
        return sprintf(
            ' <span class="jfb-required"><span aria-hidden="true">*</span>'
            . '<span class="screen-reader-text" style="%1$s">%2$s</span></span>',
            esc_attr(self::SR_ONLY_STYLE),
            esc_html__('(required)', 'jotform-bridge')
        );
    }

    /**
     * The attributes every input, textarea and select shares.
     */
    private function controlAttributes(string $id, bool $required): string
    {
        return sprintf(
            ' aria-describedby="%s"%s',
            esc_attr($id . '-error'),
            $required ? ' required aria-required="true"' : ''
        );
    }

    /**
     * The slot the frontend script writes this field's server error into.
     */
    private function errorSlot(string $id, string $key): string
    {
        return sprintf(
            '<span class="jfb-error" id="%1$s" data-jotform-field-error="%2$s"></span>',
            esc_attr($id . '-error'),
            esc_attr($key)
        );
    }

    private function autocomplete(string $token): string
    {
        return $token === '' ? '' : sprintf(' autocomplete="%s"', esc_attr($token));
    }

    /**
     * @param array<string, mixed> $field
     */
    private function numberRange(array $field): string
    {
        if ((string) $field['type'] !== FieldNormalizer::TYPE_NUMBER) {
            return '';
        }

        $attributes = '';
        $meta       = is_array($field['meta']) ? $field['meta'] : [];

        foreach (['min', 'max'] as $bound) {
            $value = isset($meta[$bound]) ? (string) $meta[$bound] : '';

            if ($value !== '') {
                $attributes .= sprintf(' %s="%s"', $bound, esc_attr($value));
            }
        }

        return $attributes;
    }

    /**
     * The text a field is labelled with.
     *
     * Jotform allows a question with no label at all, and an empty `<label>` is
     * an association to nothing: the control ends up with no accessible name,
     * or — where the field is required — with the required marker as its whole
     * name, which passes every checker and tells a visitor nothing. The
     * semantic key is the honest fallback: it is stable, it is what the
     * template author already types, and it is visible in the admin schema
     * table beside the field it belongs to.
     *
     * @param array<string, mixed> $field
     */
    private function labelFor(array $field): string
    {
        $label = (string) $field['label'];

        return $label !== '' ? $label : $this->fallbackLabel((string) $field['key']);
    }

    /**
     * Jotform does not always send a label or a sub-label; the machine-readable
     * name is a better placeholder than nothing.
     */
    private function fallbackLabel(string $key): string
    {
        return ucwords(str_replace(['.', '_', 'addr '], [' ', ' ', 'address '], $key));
    }

    private function id(string $prefix, string $key): string
    {
        return $prefix . '-' . str_replace(['.', '_'], '-', $key);
    }
}
