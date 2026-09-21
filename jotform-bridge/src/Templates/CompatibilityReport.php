<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

if (!defined('ABSPATH')) {
    exit;
}

/** The result of comparing one template against one Normalized Schema: verdict and per-field rows. */
final class CompatibilityReport
{
    public const STATUS_COMPATIBLE = 'compatible';
    public const STATUS_WARNINGS   = 'warnings';
    public const STATUS_INVALID    = 'invalid';

    // Per-row outcomes.
    public const MATCHED              = 'matched';
    public const MISSING_REQUIRED     = 'missing_required';
    public const MISSING_OPTIONAL     = 'missing_optional';
    public const UNKNOWN              = 'unknown';
    public const UNSUPPORTED_REQUIRED = 'unsupported_required';
    public const UNSUPPORTED_OPTIONAL = 'unsupported_optional';
    public const COMPOSITE_PARENT     = 'composite_parent';
    public const DYNAMIC              = 'dynamic';

    /**
     * Row outcomes that make a template unusable.
     *
     * @var array<int, string>
     */
    private const BLOCKING = [
        self::MISSING_REQUIRED,
        self::UNKNOWN,
        self::UNSUPPORTED_REQUIRED,
        self::COMPOSITE_PARENT,
    ];

    /** @var array<int, array<string, mixed>> */
    private array $rows;

    private int $dynamic;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows, int $dynamic = 0)
    {
        $this->rows    = $rows;
        $this->dynamic = $dynamic;
    }

    public function status(): string
    {
        if ($this->errors() !== []) {
            return self::STATUS_INVALID;
        }

        if ($this->warnings() !== [] || $this->dynamic > 0) {
            return self::STATUS_WARNINGS;
        }

        return self::STATUS_COMPATIBLE;
    }

    public function statusLabel(): string
    {
        switch ($this->status()) {
            case self::STATUS_INVALID:
                return __('Invalid', 'jotform-bridge');

            case self::STATUS_WARNINGS:
                return __('Compatible with warnings', 'jotform-bridge');

            default:
                return __('Compatible', 'jotform-bridge');
        }
    }

    public function isValid(): bool
    {
        return $this->status() !== self::STATUS_INVALID;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return array_values(
            array_filter(
                $this->rows,
                static fn(array $row): bool => in_array($row['status'], self::BLOCKING, true)
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function warnings(): array
    {
        return array_values(
            array_filter(
                $this->rows,
                static fn(array $row): bool => in_array(
                    $row['status'],
                    [self::MISSING_OPTIONAL, self::UNSUPPORTED_OPTIONAL, self::DYNAMIC],
                    true
                )
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function matched(): array
    {
        return array_values(
            array_filter($this->rows, static fn(array $row): bool => $row['status'] === self::MATCHED)
        );
    }

    public function dynamicCount(): int
    {
        return $this->dynamic;
    }

    /**
     * Summary lines for the admin notices.
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (array_merge($this->errors(), $this->warnings()) as $row) {
            if ((string) $row['message'] !== '') {
                $messages[] = (string) $row['message'];
            }
        }

        return $messages;
    }

    public static function label(string $status): string
    {
        $labels = [
            self::MATCHED              => __('OK', 'jotform-bridge'),
            self::MISSING_REQUIRED     => __('Missing required', 'jotform-bridge'),
            self::MISSING_OPTIONAL     => __('Missing optional', 'jotform-bridge'),
            self::UNKNOWN              => __('Unknown field', 'jotform-bridge'),
            self::UNSUPPORTED_REQUIRED => __('Unsupported required field', 'jotform-bridge'),
            self::UNSUPPORTED_OPTIONAL => __('Unsupported optional field', 'jotform-bridge'),
            self::COMPOSITE_PARENT     => __('Composite field used as one input', 'jotform-bridge'),
            self::DYNAMIC              => __('Not statically verifiable', 'jotform-bridge'),
        ];

        return $labels[$status] ?? $status;
    }
}
