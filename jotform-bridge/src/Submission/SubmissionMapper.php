<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Forms\FormSchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns validated semantic values into the parameters Jotform documents for
 * `POST /form/{formID}/submissions`:
 *
 *   scalar answer      submission[{qid}]=value
 *   composite answer   submission[{qid}][{subfield}]=value
 *   multi-value answer submission[{qid}][]=value  (repeated)
 *
 * Subfield names are the answer keys the schema carries per composite child.
 */
final class SubmissionMapper
{
    public const PARAMETER = 'submission';

    /**
     * @param array<string, string|array<int, string>> $values Semantic path => sanitized value.
     *
     * @return array<string, string|array<int, string>> Parameter name => value.
     */
    public function map(FormSchema $schema, array $values): array
    {
        $params = [];

        // Driven by the schema, so only known fields are mapped.
        foreach ($schema->supportedFields() as $field) {
            $qid = (string) $field['qid'];

            if ($qid === '') {
                continue;
            }

            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    $value = $values[(string) $child['key']] ?? null;

                    if (!is_string($value) || $value === '') {
                        continue;
                    }

                    $params[$this->name($qid, (string) $child['jotform'])] = $value;
                }

                continue;
            }

            $value = $values[(string) $field['key']] ?? null;

            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $clean = array_values(array_filter($value, static fn($item): bool => (string) $item !== ''));

                if ($clean !== []) {
                    $params[$this->name($qid, '')] = $clean;
                }

                continue;
            }

            if ($value !== '') {
                $params[$this->name($qid)] = $value;
            }
        }

        return $params;
    }

    /**
     * @param string|null $subfield Null for a scalar answer, '' for a list.
     */
    private function name(string $qid, ?string $subfield = null): string
    {
        if ($subfield === null) {
            return self::PARAMETER . '[' . $qid . ']';
        }

        return self::PARAMETER . '[' . $qid . '][' . $subfield . ']';
    }
}
