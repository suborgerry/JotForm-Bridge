<?php

declare(strict_types=1);

namespace JotformBridge\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One local form endpoint: the entity theme code addresses by slug.
 *
 * It owns the binding between a Jotform form and a template, so no public code
 * ever needs the Jotform form ID. Several integrations may point at the same
 * Jotform form — that is a requirement, not an accident.
 */
final class Integration
{
    public const MODE_CUSTOM = 'custom';
    public const MODE_AUTO   = 'auto';

    private string $slug;

    private string $name;

    private string $formId;

    private string $mode;

    private string $templateSlug;

    private bool $active;

    private int $createdAt;

    private int $updatedAt;

    public function __construct(
        string $slug,
        string $name,
        string $formId,
        string $mode,
        string $templateSlug,
        bool $active,
        int $createdAt = 0,
        int $updatedAt = 0
    ) {
        $this->slug         = $slug;
        $this->name         = $name;
        $this->formId       = $formId;
        $this->mode         = self::isMode($mode) ? $mode : self::MODE_CUSTOM;
        $this->templateSlug = $templateSlug;
        $this->active       = $active;
        $this->createdAt    = $createdAt;
        $this->updatedAt    = $updatedAt;
    }

    /**
     * @return array<string, string> Mode => human readable label.
     */
    public static function modes(): array
    {
        return [
            self::MODE_CUSTOM => __('Custom template', 'jotform-bridge'),
            self::MODE_AUTO   => __('Auto (rendered from the schema)', 'jotform-bridge'),
        ];
    }

    public static function isMode(string $mode): bool
    {
        return array_key_exists($mode, self::modes());
    }

    /**
     * Sanitizes a raw admin input slice into an integration.
     *
     * Validation of what the values mean together (unique slug, known template)
     * is the repository's job; this only guarantees the types and character set.
     *
     * @param array<string, mixed> $input Unslashed request slice.
     */
    public static function fromInput(array $input): self
    {
        $name = sanitize_text_field(self::scalar($input, 'name'));

        $slug = self::scalar($input, 'slug');
        $slug = sanitize_key($slug !== '' ? $slug : $name);

        $formId = self::scalar($input, 'form_id');
        $formId = ctype_digit($formId) ? $formId : '';

        $mode = sanitize_key(self::scalar($input, 'mode'));

        $template = sanitize_key(self::scalar($input, 'template'));

        return new self(
            $slug,
            $name,
            $formId,
            $mode,
            $template,
            !empty($input['active'])
        );
    }

    /**
     * Reads one value out of a raw request slice, ignoring arrays and objects.
     *
     * A forged `jotform_integration[slug][]=x` must not turn into the literal
     * string "Array"; it is simply not a value this form can carry.
     *
     * @param array<string, mixed> $input
     */
    private static function scalar(array $input, string $key): string
    {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $mode = self::scalar($data, 'mode');

        return new self(
            self::scalar($data, 'slug'),
            self::scalar($data, 'name'),
            self::scalar($data, 'form_id'),
            $mode !== '' ? $mode : self::MODE_CUSTOM,
            self::scalar($data, 'template'),
            !empty($data['active']),
            isset($data['created_at']) && is_scalar($data['created_at']) ? (int) $data['created_at'] : 0,
            isset($data['updated_at']) && is_scalar($data['updated_at']) ? (int) $data['updated_at'] : 0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug'       => $this->slug,
            'name'       => $this->name,
            'form_id'    => $this->formId,
            'mode'       => $this->mode,
            'template'   => $this->templateSlug,
            'active'     => $this->active,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function formId(): string
    {
        return $this->formId;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function modeLabel(): string
    {
        return self::modes()[$this->mode] ?? $this->mode;
    }

    public function templateSlug(): string
    {
        return $this->templateSlug;
    }

    public function usesCustomTemplate(): bool
    {
        return $this->mode === self::MODE_CUSTOM;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function updatedAt(): int
    {
        return $this->updatedAt;
    }

    public function withSlug(string $slug): self
    {
        $clone       = clone $this;
        $clone->slug = $slug;

        return $clone;
    }

    public function withActive(bool $active): self
    {
        $clone         = clone $this;
        $clone->active = $active;

        return $clone;
    }

    public function withTimestamps(int $createdAt, int $updatedAt): self
    {
        $clone            = clone $this;
        $clone->createdAt = $createdAt;
        $clone->updatedAt = $updatedAt;

        return $clone;
    }
}
