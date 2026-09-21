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
 * Builds a plain, accessible form directly from the Normalized Schema, using
 * the same `data-jotform-*` contract as custom templates. Unsupported fields
 * are skipped.
 */
final class AutoRenderer
{
    /** Filter for replacing or wrapping the markup of one field. */
    public const FILTER_FIELD_HTML = 'jotform_bridge_auto_field_html';

    /** Per-request counter keeping element IDs unique across several forms. */
    private int $instances = 0;

    /** Core's `.screen-reader-text` declarations, inlined. */
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
             * The returned string is printed as is. `$html` is escaped markup;
             * `$field` is the raw normalized entry, so anything taken from it
             * has to be escaped by the callback:
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
            Honeypot::markup($prefix) . Turnstile::markup(),
            esc_html__('Submit', 'jotform-bridge')
        );
    }

    /** The no-JavaScript notice; also exposed to custom templates as `$noscript`. */
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
     * A radio or checkbox group sharing one semantic path.
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
                esc_attr($id . '-error'),
                // `required` on every checkbox would demand every box; radios only.
                $required && $type === FieldNormalizer::TYPE_RADIO ? ' required' : '',
                esc_html((string) $option['label'])
            );
        }

        // No `aria-required` on the fieldset: the `group` role does not support it.
        return sprintf(
            '<fieldset class="jfb-field jfb-field--%1$s">%2$s%3$s%4$s</fieldset>',
            esc_attr($type),
            $this->legend($this->labelFor($field), $required),
            $choices,
            $this->errorSlot($id, $key)
        );
    }

    /**
     * A composite field: a fieldset whose children each carry their own path.
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
        // The empty first option makes a required select enforceable.
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

        // Inline style as well as the class: nothing guarantees a theme defines it.
        return sprintf(
            ' <span class="jfb-required"><span aria-hidden="true">*</span>'
            . '<span class="screen-reader-text" style="%1$s">%2$s</span></span>',
            esc_attr(self::SR_ONLY_STYLE),
            esc_html__('(required)', 'jotform-bridge')
        );
    }

    private function controlAttributes(string $id, bool $required): string
    {
        return sprintf(
            ' aria-describedby="%s"%s',
            esc_attr($id . '-error'),
            $required ? ' required aria-required="true"' : ''
        );
    }

    /** The slot the frontend script writes this field's server error into. */
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
     * The field's label, falling back to its semantic key when Jotform has none.
     *
     * @param array<string, mixed> $field
     */
    private function labelFor(array $field): string
    {
        $label = (string) $field['label'];

        return $label !== '' ? $label : $this->fallbackLabel((string) $field['key']);
    }

    private function fallbackLabel(string $key): string
    {
        return ucwords(str_replace(['.', '_', 'addr '], [' ', ' ', 'address '], $key));
    }

    private function id(string $prefix, string $key): string
    {
        return $prefix . '-' . str_replace(['.', '_'], '-', $key);
    }
}
