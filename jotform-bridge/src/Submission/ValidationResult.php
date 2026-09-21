<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/** Outcome of validating one submission: sanitized values, or a per-field error map. */
final class ValidationResult
{
    /** Error key for problems with the request as a whole. */
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
     * Empty unless the result is valid.
     *
     * @return array<string, string|array<int, string>>
     */
    public function values(): array
    {
        return $this->isValid() ? $this->values : [];
    }
}
