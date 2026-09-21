<?php

declare(strict_types=1);

namespace JotformBridge\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One local form endpoint, addressed by slug. Owns the binding between a
 * Jotform form and a template; several integrations may share one form.
 */
final class Integration
{
    public const MODE_CUSTOM = 'custom';
    public const MODE_AUTO   = 'auto';

    /** Auto: the mode that renders without a template file. */
    public const MODE_DEFAULT = self::MODE_AUTO;

    public const SUCCESS_MESSAGE  = 'message';
    public const SUCCESS_REDIRECT = 'redirect';

    /** Longest configurable redirect delay, in seconds. */
    public const MAX_REDIRECT_DELAY = 60;

    private string $slug;

    private string $name;

    private string $formId;

    private string $mode;

    private string $templateSlug;

    private int $createdAt;

    private int $updatedAt;

    private string $successAction;

    private int $redirectPageId;

    private int $redirectDelay;

    /** @var array<int, array<string, string>> */
    private array $conditions;

    /**
     * @param array<int, array<string, string>> $conditions
     */
    public function __construct(
        string $slug,
        string $name,
        string $formId,
        string $mode,
        string $templateSlug,
        int $createdAt = 0,
        int $updatedAt = 0,
        string $successAction = self::SUCCESS_MESSAGE,
        int $redirectPageId = 0,
        int $redirectDelay = 0,
        array $conditions = []
    ) {
        $this->conditions     = ConditionalLogic::sanitize($conditions);
        $this->slug           = $slug;
        $this->name           = $name;
        $this->formId         = $formId;
        $this->mode           = self::isMode($mode) ? $mode : self::MODE_DEFAULT;
        $this->templateSlug   = $templateSlug;
        $this->createdAt      = $createdAt;
        $this->updatedAt      = $updatedAt;
        $this->successAction  = self::isSuccessAction($successAction) ? $successAction : self::SUCCESS_MESSAGE;
        $this->redirectPageId = max(0, $redirectPageId);
        $this->redirectDelay  = self::clampDelay($redirectDelay);
    }

    public static function clampDelay(int $seconds): int
    {
        return min(self::MAX_REDIRECT_DELAY, max(0, $seconds));
    }

    /**
     * @return array<string, string> Mode => human readable label.
     */
    public static function modes(): array
    {
        return [
            self::MODE_AUTO   => __('Auto (rendered from the schema)', 'jotform-bridge'),
            self::MODE_CUSTOM => __('Custom template', 'jotform-bridge'),
        ];
    }

    public static function isMode(string $mode): bool
    {
        return array_key_exists($mode, self::modes());
    }

    /**
     * @return array<string, string> Success action => human readable label.
     */
    public static function successActions(): array
    {
        return [
            self::SUCCESS_MESSAGE  => __('Show the success message', 'jotform-bridge'),
            self::SUCCESS_REDIRECT => __('Redirect to a page', 'jotform-bridge'),
        ];
    }

    public static function isSuccessAction(string $action): bool
    {
        return array_key_exists($action, self::successActions());
    }

    /**
     * Sanitizes a raw admin input slice into an integration; cross-field
     * validation is the repository's job.
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

        $successAction = sanitize_key(self::scalar($input, 'success_action'));

        return new self(
            $slug,
            $name,
            $formId,
            $mode,
            $template,
            0,
            0,
            $successAction,
            (int) self::scalar($input, 'redirect_page_id'),
            (int) self::scalar($input, 'redirect_delay'),
            ConditionalLogic::sanitize($input['conditions'] ?? [])
        );
    }

    /**
     * One scalar value out of a raw request slice; arrays are ignored.
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
            $mode !== '' ? $mode : self::MODE_DEFAULT,
            self::scalar($data, 'template'),
            isset($data['created_at']) && is_scalar($data['created_at']) ? (int) $data['created_at'] : 0,
            isset($data['updated_at']) && is_scalar($data['updated_at']) ? (int) $data['updated_at'] : 0,
            self::scalar($data, 'success_action'),
            (int) self::scalar($data, 'redirect_page_id'),
            (int) self::scalar($data, 'redirect_delay'),
            ConditionalLogic::sanitize($data['conditions'] ?? [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug'             => $this->slug,
            'name'             => $this->name,
            'form_id'          => $this->formId,
            'mode'             => $this->mode,
            'template'         => $this->templateSlug,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
            'success_action'   => $this->successAction,
            'redirect_page_id' => $this->redirectPageId,
            'redirect_delay'   => $this->redirectDelay,
            'conditions'       => $this->conditions,
        ];
    }

    /** @return array<int, array<string, string>> */
    public function conditions(): array
    {
        return $this->conditions;
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

    public function successAction(): string
    {
        return $this->successAction;
    }

    public function successActionLabel(): string
    {
        return self::successActions()[$this->successAction] ?? $this->successAction;
    }

    /** Whether the configuration asks for a redirect; RedirectTarget decides if one can be served. */
    public function redirectsOnSuccess(): bool
    {
        return $this->successAction === self::SUCCESS_REDIRECT;
    }

    public function redirectPageId(): int
    {
        return $this->redirectPageId;
    }

    public function redirectDelay(): int
    {
        return $this->redirectDelay;
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

    public function withTimestamps(int $createdAt, int $updatedAt): self
    {
        $clone            = clone $this;
        $clone->createdAt = $createdAt;
        $clone->updatedAt = $updatedAt;

        return $clone;
    }
}
