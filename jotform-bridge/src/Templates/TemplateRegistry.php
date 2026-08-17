<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The cached result of template discovery, and the allowlist of files the
 * plugin will ever render.
 *
 * A path is renderable only because it is in here, and it is in here only
 * because TemplateScanner found it inside a trusted directory. Nothing else may
 * be treated as a template.
 */
final class TemplateRegistry
{
    public const OPTION = 'jotform_bridge_templates';

    private TemplateScanner $scanner;

    /** @var array<string, mixed>|null In-request memoization. */
    private ?array $loaded = null;

    public function __construct(?TemplateScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new TemplateScanner();
    }

    /**
     * Reads the registry, scanning once if nothing has been stored yet.
     *
     * A normal page request must never trigger a filesystem scan beyond that
     * first cold read; changes are picked up through rescan().
     *
     * @return array<string, array<string, mixed>> Slug => entry.
     */
    public function all(): array
    {
        $registry = $this->load();

        return $registry['templates'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $slug): ?array
    {
        $slug = sanitize_key($slug);

        return $this->all()[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return $this->get($slug) !== null;
    }

    /**
     * The one place allowed to answer "which file renders this template?".
     *
     * Returns null when the slug is unknown or the file has since disappeared,
     * so a caller can never be handed a path that is not currently valid.
     */
    public function file(string $slug): ?string
    {
        $entry = $this->get($slug);

        if ($entry === null) {
            return null;
        }

        $file = (string) $entry['file'];

        return is_file($file) && is_readable($file) ? $file : null;
    }

    /**
     * @return array<int, string> The semantic identifiers found in a template.
     */
    public function fields(string $slug): array
    {
        $entry = $this->get($slug);

        if ($entry === null || !is_array($entry['fields'])) {
            return [];
        }

        return array_values(array_map('strval', $entry['fields']));
    }

    public function dynamicCount(string $slug): int
    {
        $entry = $this->get($slug);

        return $entry === null ? 0 : (int) $entry['dynamic'];
    }

    /**
     * @return array<string, string> Slug => name, for a select element.
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->all() as $slug => $entry) {
            $choices[$slug] = (string) $entry['name'];
        }

        return $choices;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function diagnostics(): array
    {
        return $this->load()['diagnostics'];
    }

    /**
     * @return array<int, array{path:string, source:string}>
     */
    public function roots(): array
    {
        return $this->load()['roots'];
    }

    public function generatedAt(): int
    {
        return $this->load()['generated_at'];
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * Drops the cache, scans again and stores the result.
     *
     * @return array<string, mixed> The fresh registry.
     */
    public function rescan(): array
    {
        $this->flush();

        return $this->load();
    }

    public function flush(): void
    {
        $this->loaded = null;

        delete_option(self::OPTION);
    }

    /**
     * @return array{
     *     templates: array<string, array<string, mixed>>,
     *     diagnostics: array<int, array<string, string>>,
     *     roots: array<int, array{path:string, source:string}>,
     *     generated_at: int
     * }
     */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = get_option(self::OPTION, null);

        if (is_array($stored) && isset($stored['templates'], $stored['generated_at'])) {
            $this->loaded = $this->normalize($stored);

            return $this->loaded;
        }

        $scanned                 = $this->scanner->scan();
        $scanned['generated_at'] = time();

        update_option(self::OPTION, $scanned, false);

        $this->loaded = $this->normalize($scanned);

        return $this->loaded;
    }

    /**
     * @param array<string, mixed> $registry
     *
     * @return array{
     *     templates: array<string, array<string, mixed>>,
     *     diagnostics: array<int, array<string, string>>,
     *     roots: array<int, array{path:string, source:string}>,
     *     generated_at: int
     * }
     */
    private function normalize(array $registry): array
    {
        $templates = [];

        if (isset($registry['templates']) && is_array($registry['templates'])) {
            foreach ($registry['templates'] as $slug => $entry) {
                if (!is_array($entry) || !isset($entry['file'], $entry['name'])) {
                    continue;
                }

                $templates[(string) $slug] = [
                    'slug'     => (string) $slug,
                    'name'     => (string) $entry['name'],
                    'file'     => (string) $entry['file'],
                    'source'   => isset($entry['source']) ? (string) $entry['source'] : '',
                    'fields'   => isset($entry['fields']) && is_array($entry['fields']) ? $entry['fields'] : [],
                    'dynamic'  => isset($entry['dynamic']) ? (int) $entry['dynamic'] : 0,
                    'mtime'    => isset($entry['mtime']) ? (int) $entry['mtime'] : 0,
                    'priority' => isset($entry['priority']) ? (int) $entry['priority'] : 0,
                ];
            }
        }

        return [
            'templates'    => $templates,
            'diagnostics'  => isset($registry['diagnostics']) && is_array($registry['diagnostics'])
                ? array_values($registry['diagnostics'])
                : [],
            'roots'        => isset($registry['roots']) && is_array($registry['roots'])
                ? array_values($registry['roots'])
                : [],
            'generated_at' => isset($registry['generated_at']) ? (int) $registry['generated_at'] : 0,
        ];
    }
}
