<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Normalized Schema of one Jotform form.
 *
 * This is the internal contract every later layer works against: templates,
 * validation, rendering and submission mapping. It is a plain read-only value
 * object over normalized field arrays, so it survives a round trip through a
 * WordPress transient without custom serialization.
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
     * Every semantic path a template may use: scalar fields by their own key,
     * composite fields by their children only.
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
     * Required semantic paths, expanded the same way as semanticPaths().
     *
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

    /**
     * Backend-only lookup. Templates and REST payloads never see the qid.
     */
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

    /**
     * A schema with errors is still readable, but must not be trusted for
     * rendering or submission mapping.
     */
    public function isUsable(): bool
    {
        return $this->errors() === [];
    }

    /**
     * Deterministic hash over the structurally significant parts of the schema.
     */
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
            isset($data['form_id']) ? (string) $data['form_id'] : '',
            isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [],
            isset($data['diagnostics']) && is_array($data['diagnostics']) ? $data['diagnostics'] : [],
            isset($data['fingerprint']) ? (string) $data['fingerprint'] : ''
        );
    }
}
