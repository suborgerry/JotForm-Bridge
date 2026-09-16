<?php

declare(strict_types=1);

namespace JotformBridge\Integrations;

use JotformBridge\Forms\FormSchema;

if (!defined('ABSPATH')) {
    exit;
}

/** Local, independent conditions addressed exclusively by semantic paths. */
final class ConditionalLogic
{
    public const MAX_RULES = 50;

    /** @return array<string, string> */
    public static function actions(): array
    {
        return [
            'show' => __('Show only when', 'jotform-bridge'),
            'require' => __('Require only when', 'jotform-bridge'),
        ];
    }

    /** @return array<string, string> */
    public static function operators(): array
    {
        return [
            'equals' => __('Equals / includes', 'jotform-bridge'),
            'not_equals' => __('Does not equal / include', 'jotform-bridge'),
            'empty' => __('Is empty', 'jotform-bridge'),
            'not_empty' => __('Is not empty', 'jotform-bridge'),
        ];
    }

    /**
     * Malformed rows remain invalid rather than silently losing protection.
     *
     * @param mixed $input
     * @return array<int, array<string, string>>
     */
    public static function sanitize($input): array
    {
        if (!is_array($input)) {
            return [['action' => '', 'target' => '', 'source' => '', 'operator' => '', 'value' => '']];
        }

        $rules = [];
        foreach (array_slice($input, 0, self::MAX_RULES + 1) as $row) {
            $rule = [];
            foreach (['action', 'target', 'source', 'operator', 'value'] as $key) {
                $value = is_array($row) && isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : '';
                // Option values are literal text: stripping tags or percent
                // sequences would change what a condition compares against.
                // Every consumer escapes this value at its output boundary.
                $rule[$key] = $key === 'value'
                    ? trim(str_replace(chr(0), '', wp_check_invalid_utf8($value, true)))
                    : sanitize_text_field($value);
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * @param array<int, array<string, string>> $rules
     * @return array<int, string>
     */
    public static function errors(array $rules, ?FormSchema $schema = null): array
    {
        if (count($rules) > self::MAX_RULES) {
            return [__('Too many conditional rules. The maximum is 50.', 'jotform-bridge')];
        }

        $errors = [];
        $seen = [];
        $hiddenTargets = [];
        foreach ($rules as $rule) {
            if ($rule['action'] === 'show') {
                $hiddenTargets[] = $rule['target'];
            }
        }
        foreach ($rules as $index => $rule) {
            $valid = isset(self::actions()[$rule['action']], self::operators()[$rule['operator']]);
            foreach (['target', 'source'] as $key) {
                $valid = $valid && strlen($rule[$key]) <= 128
                    && preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D', $rule[$key]) === 1;
            }
            $id = $rule['action'] . ':' . $rule['target'];
            $valid = $valid && !isset($seen[$id]) && $rule['target'] !== $rule['source']
                && !in_array($rule['source'], $hiddenTargets, true) && strlen($rule['value']) <= 1000;
            if (in_array($rule['operator'], ['equals', 'not_equals'], true)) {
                $valid = $valid && $rule['value'] !== '';
            }
            if ($schema !== null) {
                $paths = $schema->semanticPaths();
                $valid = $valid && in_array($rule['target'], $paths, true) && in_array($rule['source'], $paths, true);
            }
            if (!$valid) {
                $errors[] = sprintf(
                    /* translators: %d: rule number */
                    __('Conditional rule %d is invalid. Use known semantic fields, a unique action per target, and a source that is never conditionally hidden.', 'jotform-bridge'),
                    $index + 1
                );
            }
            $seen[$id] = true;
        }

        return $errors;
    }

    /**
     * @param array<int, array<string, string>> $rules Validated rules.
     * @param array<string, string|array<int, string>> $values Sanitized source values.
     * @return array<string, array<string, bool>>
     */
    public static function state(array $rules, array $values): array
    {
        $state = [];
        foreach ($rules as $rule) {
            $value = $values[$rule['source']] ?? '';
            $list = is_array($value) ? $value : [$value];
            $list = array_values(array_filter($list, static fn(string $item): bool => trim($item) !== ''));
            $equal = in_array($rule['value'], $list, true);
            $matches = match ($rule['operator']) {
                'equals' => $equal,
                'not_equals' => !$equal,
                'empty' => $list === [],
                'not_empty' => $list !== [],
                default => false,
            };
            $state[$rule['target']][$rule['action']] = $matches;
        }

        return $state;
    }
}
