<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Forms\FieldNormalizer;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Integrations\Integration;

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
             * taking over the whole form; the returned string is printed as is,
             * so a callback that builds markup is responsible for escaping it.
             *
             * @param string               $html        Escaped field markup.
             * @param array<string, mixed> $field       Normalized field.
             * @param string               $integration Integration slug.
             */
            $parts[] = (string) apply_filters(self::FILTER_FIELD_HTML, $html, $field, $slug);
        }

        if ($parts === []) {
            return '';
        }

        return sprintf(
            '<form class="jfb-form" method="post" action="%1$s" data-jotform-bridge data-jotform-integration="%2$s">'
            . '<p class="jfb-success" data-jotform-success role="status" aria-live="polite"></p>'
            . '<div class="jfb-error jfb-error--form" data-jotform-errors role="alert" aria-live="assertive"></div>'
            . '%3$s'
            . '<div class="jfb-actions"><button type="submit" class="jfb-submit">%4$s</button></div>'
            . '</form>',
            esc_url($endpoint),
            esc_attr($slug),
            implode('', $parts),
            esc_html__('Submit', 'jotform-bridge')
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
            $this->label($id, (string) $field['label'], $required),
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
                . '<input type="%2$s" id="%1$s" name="%3$s" value="%4$s" data-jotform-field="%5$s"%6$s>'
                . '<span class="jfb-choice-label">%7$s</span>'
                . '</label>',
                esc_attr($optionId),
                esc_attr($type === FieldNormalizer::TYPE_RADIO ? 'radio' : 'checkbox'),
                esc_attr($id),
                esc_attr((string) $option['value']),
                esc_attr($key),
                // A required radio group is satisfied by any one of its inputs,
                // so the attribute belongs on each of them. A required checkbox
                // group is not: `required` there would demand every single box,
                // so the requirement is left to the server.
                $required && $type === FieldNormalizer::TYPE_RADIO ? ' required' : '',
                esc_html((string) $option['label'])
            );
        }

        return sprintf(
            '<fieldset class="jfb-field jfb-field--%1$s"%2$s>%3$s%4$s%5$s</fieldset>',
            esc_attr($type),
            $required ? ' aria-required="true"' : '',
            $this->legend((string) $field['label'], $required),
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
                : $this->childFallbackLabel((string) $child['child']);

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
            $this->legend((string) $field['label'], (bool) $field['required']),
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

        return sprintf(
            ' <span class="jfb-required"><span aria-hidden="true">*</span>'
            . '<span class="screen-reader-text">%s</span></span>',
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
     * Jotform does not always send a sub-label; the sub-field name is a better
     * placeholder than an empty label.
     */
    private function childFallbackLabel(string $child): string
    {
        return ucwords(str_replace(['_', 'addr '], [' ', 'address '], $child));
    }

    private function id(string $prefix, string $key): string
    {
        return $prefix . '-' . str_replace(['.', '_'], '-', $key);
    }
}
