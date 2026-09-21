<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Derives the public semantic identifier from a question's stable `name`
 * property, never from its label. Pure and deterministic.
 */
final class SemanticKey
{
    /** Prefix of a key derived from the qid because the name was unusable. */
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

    public static function isFallback(string $key): bool
    {
        return strpos($key, self::FALLBACK_PREFIX) === 0;
    }

    public static function child(string $parentKey, string $childKey): string
    {
        return $parentKey . '.' . $childKey;
    }

    /** Lowercase ASCII, `_`-separated; camelCase is split (`fullName` → `full_name`). */
    private static function slug(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = strtolower($value);

        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim($value, '_');

        // A key must not start with a digit.
        if ($value !== '' && ctype_digit($value[0])) {
            $value = 'f_' . $value;
        }

        return $value;
    }
}
