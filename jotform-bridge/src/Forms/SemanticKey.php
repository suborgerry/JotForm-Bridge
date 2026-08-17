<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a Jotform question into the public semantic identifier.
 *
 * The source is the `name` property — the machine-readable slug Jotform derives
 * from the label once, and keeps stable afterwards. The visible `text` label is
 * never used: it can be renamed, translated or duplicated. The qid stays
 * authoritative inside the backend but must never become a public identifier.
 *
 * Generation is pure and deterministic: same question in, same key out.
 */
final class SemanticKey
{
    /**
     * Prefix used when Jotform gives us nothing usable to build a key from.
     */
    public const FALLBACK_PREFIX = 'field_';

    /**
     * @param string $name Raw Jotform `name` property.
     * @param string $qid  Raw Jotform question id, used only for the fallback.
     */
    public static function fromName(string $name, string $qid): string
    {
        $key = self::slug($name);

        if ($key === '') {
            $qid = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim($qid)));

            return self::FALLBACK_PREFIX . $qid;
        }

        return $key;
    }

    /**
     * Returns true when the key had to be derived from the qid.
     */
    public static function isFallback(string $key): bool
    {
        return strpos($key, self::FALLBACK_PREFIX) === 0;
    }

    /**
     * Composes the flattened path of a composite child.
     */
    public static function child(string $parentKey, string $childKey): string
    {
        return $parentKey . '.' . $childKey;
    }

    /**
     * lowercase, ASCII, `_`-separated. camelCase is split on the case change,
     * so Jotform's `fullName` becomes `full_name` rather than `fullname`.
     */
    private static function slug(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Split camelCase / PascalCase boundaries before lowercasing.
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = strtolower($value);

        // Anything that is not an ASCII letter or digit is a separator.
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim($value, '_');

        // A key must not start with a digit: `1st_choice` would be an awkward
        // public identifier and cannot be a PHP/JS property shorthand.
        if ($value !== '' && ctype_digit($value[0])) {
            $value = 'f_' . $value;
        }

        return $value;
    }
}
