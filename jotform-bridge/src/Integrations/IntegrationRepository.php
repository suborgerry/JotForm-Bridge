<?php

declare(strict_types=1);

namespace JotformBridge\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only place that reads or writes the integrations option.
 *
 * Storage is a single WordPress option keyed by slug, which makes the
 * uniqueness of the public identifier a property of the storage rather than
 * something every caller has to remember to check. No custom table.
 */
final class IntegrationRepository
{
    public const OPTION = 'jotform_bridge_integrations';

    /**
     * @return array<string, Integration> Slug => integration, ordered by name.
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [];
        }

        $integrations = [];

        foreach ($stored as $slug => $data) {
            if (!is_array($data)) {
                continue;
            }

            $data['slug'] = (string) $slug;
            $integration  = Integration::fromArray($data);

            if ($integration->slug() === '') {
                continue;
            }

            $integrations[$integration->slug()] = $integration;
        }

        uasort(
            $integrations,
            static fn(Integration $a, Integration $b): int => strcasecmp($a->name(), $b->name())
        );

        return $integrations;
    }

    /**
     * @return array<string, Integration>
     */
    public function active(): array
    {
        return array_filter($this->all(), static fn(Integration $i): bool => $i->isActive());
    }

    public function get(string $slug): ?Integration
    {
        $slug = sanitize_key($slug);

        if ($slug === '') {
            return null;
        }

        return $this->all()[$slug] ?? null;
    }

    public function exists(string $slug): bool
    {
        return $this->get($slug) !== null;
    }

    /**
     * Creates or replaces one integration.
     *
     * @param string|null $originalSlug Slug being edited, so a rename can free
     *                                  the old key. Null when creating.
     *
     * @return array<int, string> Validation errors; empty means saved.
     */
    public function save(Integration $integration, ?string $originalSlug = null): array
    {
        $originalSlug = $originalSlug === null ? null : sanitize_key($originalSlug);

        if ($originalSlug === '') {
            $originalSlug = null;
        }

        $errors = $this->validate($integration, $originalSlug);

        if ($errors !== []) {
            return $errors;
        }

        $stored  = $this->raw();
        $now     = time();
        $created = $integration->createdAt();

        if ($originalSlug !== null && isset($stored[$originalSlug])) {
            $previous = Integration::fromArray($stored[$originalSlug]);
            $created  = $previous->createdAt() > 0 ? $previous->createdAt() : $now;

            unset($stored[$originalSlug]);
        }

        if ($created === 0) {
            $created = $now;
        }

        $stored[$integration->slug()] = $integration->withTimestamps($created, $now)->toArray();

        update_option(self::OPTION, $stored, false);

        return [];
    }

    public function delete(string $slug): bool
    {
        $slug   = sanitize_key($slug);
        $stored = $this->raw();

        if ($slug === '' || !isset($stored[$slug])) {
            return false;
        }

        unset($stored[$slug]);

        update_option(self::OPTION, $stored, false);

        return true;
    }

    public function setActive(string $slug, bool $active): bool
    {
        $integration = $this->get($slug);

        if ($integration === null) {
            return false;
        }

        return $this->save($integration->withActive($active), $integration->slug()) === [];
    }

    /**
     * Every Jotform form ID referenced by at least one integration.
     *
     * @return array<int, string>
     */
    public function usedFormIds(): array
    {
        $ids = [];

        foreach ($this->all() as $integration) {
            if ($integration->formId() !== '') {
                $ids[$integration->formId()] = true;
            }
        }

        // Numeric string keys come back as integers, so they are cast back.
        return array_map('strval', array_keys($ids));
    }

    /**
     * @return array<int, string>
     */
    private function validate(Integration $integration, ?string $originalSlug): array
    {
        $errors = [];

        if ($integration->name() === '') {
            $errors[] = __('The integration needs a name.', 'jotform-bridge');
        }

        if ($integration->slug() === '') {
            $errors[] = __(
                'The slug is empty or contains no usable characters. Use lowercase letters, numbers, "-" and "_".',
                'jotform-bridge'
            );
        } elseif ($integration->slug() !== $originalSlug && $this->exists($integration->slug())) {
            $errors[] = sprintf(
                /* translators: %s: integration slug */
                __('The slug "%s" is already used by another integration.', 'jotform-bridge'),
                $integration->slug()
            );
        }

        if ($integration->formId() === '') {
            $errors[] = __('Select the Jotform form this integration submits to.', 'jotform-bridge');
        }

        if ($integration->usesCustomTemplate() && $integration->templateSlug() === '') {
            $errors[] = __('A custom-template integration needs a template.', 'jotform-bridge');
        }

        return $errors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function raw(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [];
        }

        $clean = [];

        foreach ($stored as $slug => $data) {
            if (is_array($data)) {
                $clean[(string) $slug] = $data;
            }
        }

        return $clean;
    }
}
