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

    /**
     * The mode an integration has when nothing has chosen one.
     *
     * Auto, because it is the mode that can render on its own: a new
     * integration set to `custom` needs a template file that does not exist
     * yet, so the first thing the editor would say about it is that it is
     * broken. Rendering from the synced schema needs nothing further, and
     * moving to a template afterwards is one select away — the starter
     * template on the same screen is generated from that same schema.
     */
    public const MODE_DEFAULT = self::MODE_AUTO;

    public const SUCCESS_MESSAGE  = 'message';
    public const SUCCESS_REDIRECT = 'redirect';

    /**
     * Longest redirect delay that can be configured, in seconds.
     *
     * The delay exists so the success message can be read, not so a form can
     * hold the visitor hostage: the form stays disabled for the whole delay.
     */
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

    /**
     * The redirect arguments are last and optional on purpose: an integration
     * stored before they existed is a complete integration, and reading one back
     * must not need them.
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
        int $redirectDelay = 0
    ) {
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

    /**
     * The number of seconds actually storable, whatever was asked for.
     */
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
            // The default first: the order of a select reads as a
            // recommendation, whatever the `selected` attribute says.
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
            (int) self::scalar($input, 'redirect_delay')
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
            $mode !== '' ? $mode : self::MODE_DEFAULT,
            self::scalar($data, 'template'),
            isset($data['created_at']) && is_scalar($data['created_at']) ? (int) $data['created_at'] : 0,
            isset($data['updated_at']) && is_scalar($data['updated_at']) ? (int) $data['updated_at'] : 0,
            // Absent keys are the defaults: an integration stored by an earlier
            // version stays valid and never needs a migration.
            self::scalar($data, 'success_action'),
            (int) self::scalar($data, 'redirect_page_id'),
            (int) self::scalar($data, 'redirect_delay')
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

    public function successAction(): string
    {
        return $this->successAction;
    }

    public function successActionLabel(): string
    {
        return self::successActions()[$this->successAction] ?? $this->successAction;
    }

    /**
     * Whether the *configuration* asks for a redirect. Whether one can actually
     * be served is a question about the target page, answered by RedirectTarget.
     */
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
