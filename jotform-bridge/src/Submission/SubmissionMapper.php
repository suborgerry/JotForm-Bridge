<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Forms\FormSchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns validated semantic values into Jotform submission parameters.
 *
 * This is the only place where a qid becomes visible again. The parameter shape
 * is the one Jotform documents for `POST /form/{formID}/submissions`:
 *
 *   scalar answer      submission[{qid}]=value
 *   composite answer   submission[{qid}][{subfield}]=value
 *   multi-value answer submission[{qid}][]=value  (repeated)
 *
 * Sources checked before implementing this class:
 *  - https://api.jotform.com/docs/ (authentication, endpoints, envelope)
 *  - jotform/jotform-api-python `create_form_submission()`, which turns the
 *    documented `{qid}_{subfield}` keys into `submission[{qid}][{subfield}]`
 *  - Jotform support documentation of the endpoint, which shows
 *    `submission[3][first]`, `submission[31][]` and the
 *    `application/x-www-form-urlencoded` content type.
 *
 * The subfield names are not invented here either: they are the answer keys the
 * schema already carries per composite child (`first`, `last`, `addr_line1`, …).
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

        // Driven by the schema rather than by the request, so the payload order
        // is stable and only known fields can ever be mapped.
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
     * Builds one parameter name.
     *
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
