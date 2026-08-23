<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What templates exist right now, and the allowlist of files the plugin will
 * ever render.
 *
 * A path is renderable only because it is in here, and it is in here only
 * because TemplateScanner found it inside a trusted directory. Nothing else may
 * be treated as a template.
 *
 * The list is read from the filesystem on demand and never stored. It used to
 * be a cached option refreshed by a "Rescan Templates" button, which meant a
 * developer could drop a file into the theme, not see it in the select, and
 * have no way of knowing why — and, worse, could edit a template and leave the
 * compatibility report describing the previous version. Discovery is cheap
 * enough not to need a cache: only the header of each file is read, and only
 * when something actually asks.
 *
 * Two levels, memoized per request:
 *
 *  - the header scan, which answers "what exists" and "which file is this slug";
 *  - the field analysis, which reads a whole file and is asked for only by the
 *    compatibility report on an admin screen.
 */
final class TemplateRegistry
{
    private TemplateScanner $scanner;

    /** @var array<string, mixed>|null In-request memo of the header scan. */
    private ?array $discovered = null;

    /**
     * In-request memo of the field analysis, by slug. A compatibility check
     * asks for the identifiers and the dynamic count separately, and reading
     * the file twice for that would be silly.
     *
     * @var array<string, array{fields: array<int, string>, dynamic: int}>
     */
    private array $analysed = [];

    public function __construct(?TemplateScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new TemplateScanner();
    }

    /**
     * @return array<string, array<string, mixed>> Slug => entry.
     */
    public function all(): array
    {
        return $this->load()['templates'];
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
     * The semantic identifiers a template declares, read from the file now.
     *
     * @return array<int, string>
     */
    public function fields(string $slug): array
    {
        return $this->analyse($slug)['fields'];
    }

    public function dynamicCount(string $slug): int
    {
        return $this->analyse($slug)['dynamic'];
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

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * Drops the in-request memos.
     *
     * Only useful to a long-running process — WP-CLI, a test — that changes
     * files and then asks again within the same request.
     */
    public function flush(): void
    {
        $this->discovered = null;
        $this->analysed   = [];
    }

    /**
     * @return array{fields: array<int, string>, dynamic: int}
     */
    private function analyse(string $slug): array
    {
        $slug = sanitize_key($slug);

        if (isset($this->analysed[$slug])) {
            return $this->analysed[$slug];
        }

        $file = $this->file($slug);

        return $this->analysed[$slug] = $file === null
            ? ['fields' => [], 'dynamic' => 0]
            : $this->scanner->fields($file);
    }

    /**
     * @return array{
     *     templates: array<string, array<string, mixed>>,
     *     diagnostics: array<int, array<string, string>>,
     *     roots: array<int, array{path:string, source:string}>
     * }
     */
    private function load(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        return $this->discovered = $this->normalize($this->scanner->discover());
    }

    /**
     * @param array<string, mixed> $registry
     *
     * @return array{
     *     templates: array<string, array<string, mixed>>,
     *     diagnostics: array<int, array<string, string>>,
     *     roots: array<int, array{path:string, source:string}>
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
                    'mtime'    => isset($entry['mtime']) ? (int) $entry['mtime'] : 0,
                    'priority' => isset($entry['priority']) ? (int) $entry['priority'] : 0,
                ];
            }
        }

        return [
            'templates'   => $templates,
            'diagnostics' => isset($registry['diagnostics']) && is_array($registry['diagnostics'])
                ? array_values($registry['diagnostics'])
                : [],
            'roots'       => isset($registry['roots']) && is_array($registry['roots'])
                ? array_values($registry['roots'])
                : [],
        ];
    }
}
