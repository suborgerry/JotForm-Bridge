<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Normalized Schema of one Jotform form: a read-only value object over
 * normalized field arrays, the contract every later layer works against.
 */
final class FormSchema
{
    public const DIAGNOSTIC_ERROR   = 'error';
    public const DIAGNOSTIC_WARNING = 'warning';

    public const CODE_COLLISION   = 'semantic_key_collision';
    public const CODE_NO_NAME     = 'missing_machine_name';
    public const CODE_UNSUPPORTED = 'unsupported_field';
    public const CODE_NO_OPTIONS  = 'missing_options';

    private string $formId;

    /** @var array<string, array<string, mixed>> Semantic key => field. */
    private array $fields;

    /** @var array<int, array<string, mixed>> */
    private array $diagnostics;

    private string $fingerprint;

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>>    $diagnostics
     */
    public function __construct(string $formId, array $fields, array $diagnostics, string $fingerprint)
    {
        $this->formId      = $formId;
        $this->fields      = $fields;
        $this->diagnostics = $diagnostics;
        $this->fingerprint = $fingerprint;
    }

    public function formId(): string
    {
        return $this->formId;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(string $key): ?array
    {
        return $this->fields[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function requiredFields(): array
    {
        return array_filter($this->fields, static fn(array $field): bool => (bool) $field['required']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function supportedFields(): array
    {
        return array_filter($this->fields, static fn(array $field): bool => (bool) $field['supported']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function unsupportedFields(): array
    {
        return array_filter($this->fields, static fn(array $field): bool => !$field['supported']);
    }

    /**
     * Every semantic path a template may use; composites by their children only.
     *
     * @return array<int, string>
     */
    public function semanticPaths(): array
    {
        $paths = [];

        foreach ($this->supportedFields() as $field) {
            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    $paths[] = (string) $child['key'];
                }

                continue;
            }

            $paths[] = (string) $field['key'];
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    public function requiredPaths(): array
    {
        $paths = [];

        foreach ($this->supportedFields() as $field) {
            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    if ($child['required']) {
                        $paths[] = (string) $child['key'];
                    }
                }

                continue;
            }

            if ($field['required']) {
                $paths[] = (string) $field['key'];
            }
        }

        return $paths;
    }

    public function qidFor(string $key): ?string
    {
        $field = $this->field($key);

        return $field === null ? null : (string) $field['qid'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return array_values(
            array_filter(
                $this->diagnostics,
                static fn(array $d): bool => $d['level'] === self::DIAGNOSTIC_ERROR
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collisions(): array
    {
        return array_values(
            array_filter(
                $this->diagnostics,
                static fn(array $d): bool => $d['code'] === self::CODE_COLLISION
            )
        );
    }

    public function hasCollisions(): bool
    {
        return $this->collisions() !== [];
    }

    /** False when the schema has errors; it must then not be rendered or mapped. */
    public function isUsable(): bool
    {
        return $this->errors() === [];
    }

    /** Deterministic hash of the structurally significant parts. */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'form_id'     => $this->formId,
            'fields'      => $this->fields,
            'diagnostics' => $this->diagnostics,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['form_id']) && is_scalar($data['form_id']) ? (string) $data['form_id'] : '',
            self::readFields($data['fields'] ?? null),
            isset($data['diagnostics']) && is_array($data['diagnostics'])
                ? array_values(array_filter($data['diagnostics'], 'is_array'))
                : [],
            isset($data['fingerprint']) && is_scalar($data['fingerprint']) ? (string) $data['fingerprint'] : ''
        );
    }

    /**
     * Drops stored entries missing a key every later layer reads.
     *
     * @param mixed $fields
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readFields($fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $clean = [];

        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            $expected = [
                'key',
                'qid',
                'type',
                'label',
                'required',
                'supported',
                'multiple',
                'allow_other',
                'children',
                'options',
                'meta',
            ];

            foreach ($expected as $required) {
                if (!array_key_exists($required, $field)) {
                    continue 2;
                }
            }

            if (!is_array($field['children']) || !is_array($field['options'])) {
                continue;
            }

            $clean[(string) $key] = $field;
        }

        return $clean;
    }
}
