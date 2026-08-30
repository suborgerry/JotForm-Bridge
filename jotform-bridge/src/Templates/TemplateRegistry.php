<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

use JotformBridge\Support\Logger;

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
 *
 * @phpstan-type Registry array{
 *     templates: array<string, array<string, mixed>>,
 *     diagnostics: array<int, array<string, string>>,
 *     roots: array<int, array{path:string, source:string}>
 * }
 */
final class TemplateRegistry
{
    private TemplateScanner $scanner;

    /** @var Registry|null In-request memo of the header scan. */
    private ?array $discovered = null;

    /**
     * Where a rejected template file goes now that no screen lists one.
     *
     * Null on the frontend and in tests, where nothing is listening.
     */
    private ?Logger $logger;

    /**
     * In-request memo of the field analysis, by slug. A compatibility check
     * asks for the identifiers and the dynamic count separately, and reading
     * the file twice for that would be silly.
     *
     * @var array<string, array{fields: array<int, string>, dynamic: int}>
     */
    private array $analysed = [];

    public function __construct(?TemplateScanner $scanner = null, ?Logger $logger = null)
    {
        $this->scanner = $scanner ?? new TemplateScanner();
        $this->logger  = $logger;
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
     * @return Registry
     */
    private function load(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $registry = $this->normalize($this->scanner->discover());

        $this->discovered = $registry;

        $this->report($registry['diagnostics']);

        return $registry;
    }

    /**
     * A file that was skipped, and why.
     *
     * This used to be a "Template diagnostics" list at the foot of the
     * integrations screen, which nobody could act on: it printed a level and a
     * message, threw the code, the file and the slug away, and showed a notice
     * about a parent-theme template no integration had ever been bound to. The
     * reader it was plausibly for is the developer who has just added a file
     * and is asking why it is not in the select — and that person is better
     * served by a log line naming the file than by a sentence on a screen about
     * something else.
     *
     * Notices are dropped rather than logged: an overridden template is the
     * child-theme mechanism working, not an incident.
     *
     * @param array<int, array<string, string>> $diagnostics
     */
    private function report(array $diagnostics): void
    {
        if ($this->logger === null) {
            return;
        }

        foreach ($diagnostics as $diagnostic) {
            $level = (string) ($diagnostic['level'] ?? '');

            if ($level !== TemplateScanner::LEVEL_ERROR && $level !== TemplateScanner::LEVEL_WARNING) {
                continue;
            }

            $context = [
                'code' => (string) ($diagnostic['code'] ?? ''),
                'file' => (string) ($diagnostic['file'] ?? ''),
                'slug' => (string) ($diagnostic['slug'] ?? ''),
            ];

            $message = 'Template skipped: ' . (string) ($diagnostic['message'] ?? '');

            if ($level === TemplateScanner::LEVEL_ERROR) {
                $this->logger->error($message, $context);
                continue;
            }

            $this->logger->debug($message, $context);
        }
    }

    /**
     * @param array<string, mixed> $registry
     *
     * @return Registry
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
