<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The templates on disk, and the allowlist of files the plugin will render.
 * Read from the theme on demand and memoized per request only: the header
 * scan answers what exists, the field analysis reads a whole file and is
 * used by the admin compatibility report.
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

    /** Receives the diagnostics of rejected template files. */
    private ?Logger $logger;

    /**
     * In-request memo of the field analysis, by slug.
     *
     * @var array<string, array{fields: array<int, string>, dynamic: int}>
     */
    private array $analysed = [];

    public function __construct(?Logger $logger = null)
    {
        $this->scanner = new TemplateScanner();
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

    /** The file that renders a template; null when unknown or gone. */
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

    /** Drops the in-request memos. */
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
     * Logs skipped files; notices (an overridden template) are dropped.
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
