<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Outcome of validating one submission.
 *
 * Holds the sanitized values on success and a per-field error map on failure.
 * The map is exactly what the REST response contract exposes, so no other layer
 * has to reshape it.
 */
final class ValidationResult
{
    /**
     * Error key used for problems that belong to the request as a whole rather
     * than to one field.
     */
    public const FORM_KEY = '_form';

    /** @var array<string, string> Semantic path => message. */
    private array $errors;

    /** @var array<string, string|array<int, string>> Semantic path => sanitized value. */
    private array $values;

    /**
     * @param array<string, string>                    $errors
     * @param array<string, string|array<int, string>> $values
     */
    public function __construct(array $errors, array $values)
    {
        $this->errors = $errors;
        $this->values = $values;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Only meaningful when the result is valid; a failed validation must never
     * hand values on to the mapper.
     *
     * @return array<string, string|array<int, string>>
     */
    public function values(): array
    {
        return $this->isValid() ? $this->values : [];
    }
}
