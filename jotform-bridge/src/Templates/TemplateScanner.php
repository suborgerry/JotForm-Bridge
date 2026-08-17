<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Discovers custom form templates by reading — never executing — theme files.
 *
 * The scanner is the only component that touches the filesystem. It works
 * exclusively from paths it derives itself (theme directories) or that a
 * developer adds through the `jotform_bridge_template_paths` filter. A path
 * originating in a request never reaches this class: there is no code path that
 * would let it.
 */
final class TemplateScanner
{
    public const HEADER_NAME = 'Jotform Template Name';
    public const HEADER_SLUG = 'Jotform Template Slug';

    /**
     * Binding a template to one specific Jotform form belongs to the
     * Integration, so this header is rejected rather than ignored.
     */
    public const HEADER_FORBIDDEN = 'Jotform Form ID';

    public const DIRECTORY = 'forms';

    public const SOURCE_CHILD_THEME  = 'child_theme';
    public const SOURCE_PARENT_THEME = 'parent_theme';
    public const SOURCE_THEME        = 'theme';
    public const SOURCE_FILTER       = 'filter';

    public const LEVEL_ERROR   = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_NOTICE  = 'notice';

    public const CODE_MISSING_NAME    = 'missing_name';
    public const CODE_MISSING_SLUG    = 'missing_slug';
    public const CODE_INVALID_SLUG    = 'invalid_slug';
    public const CODE_NORMALIZED_SLUG = 'normalized_slug';
    public const CODE_FORBIDDEN       = 'forbidden_header';
    public const CODE_DUPLICATE       = 'duplicate_slug';
    public const CODE_OVERRIDDEN      = 'template_overridden';
    public const CODE_UNREADABLE      = 'unreadable_file';
    public const CODE_OUTSIDE_ROOT    = 'outside_template_root';
    public const CODE_DYNAMIC_FIELD   = 'dynamic_field';
    public const CODE_NO_FIELDS       = 'no_fields';

    /**
     * Enough for a file header. Reading further would only slow the scan down.
     */
    private const HEADER_BYTES = 8192;

    /**
     * Upper bound for the source we search for field identifiers. A form
     * template larger than this is not a form template.
     */
    private const SOURCE_BYTES = 262144;

    /**
     * @return array{
     *     templates: array<string, array<string, mixed>>,
     *     diagnostics: array<int, array<string, string>>,
     *     roots: array<int, array{path:string, source:string}>
     * }
     */
    public function scan(): array
    {
        $roots       = $this->roots();
        $templates   = [];
        $diagnostics = [];

        foreach ($roots as $priority => $root) {
            foreach ($this->phpFiles($root['path'], $diagnostics) as $file) {
                $template = $this->readTemplate($file, $root['source'], $diagnostics);

                if ($template === null) {
                    continue;
                }

                $slug = (string) $template['slug'];

                if (isset($templates[$slug])) {
                    $diagnostics[] = $this->duplicateDiagnostic($templates[$slug], $template, $priority);

                    continue;
                }

                $template['priority'] = $priority;
                $templates[$slug]     = $template;
            }
        }

        ksort($templates);

        return [
            'templates'   => $templates,
            'diagnostics' => $diagnostics,
            'roots'       => $roots,
        ];
    }

    /**
     * The directories that may contain templates, most specific first.
     *
     * @return array<int, array{path:string, source:string}>
     */
    public function roots(): array
    {
        $stylesheet = trailingslashit(get_stylesheet_directory()) . self::DIRECTORY;
        $template   = trailingslashit(get_template_directory()) . self::DIRECTORY;

        $candidates = [];

        if ($stylesheet === $template) {
            $candidates[$stylesheet] = self::SOURCE_THEME;
        } else {
            $candidates[$stylesheet] = self::SOURCE_CHILD_THEME;
            $candidates[$template]   = self::SOURCE_PARENT_THEME;
        }

        /**
         * Filters the directories scanned for custom form templates.
         *
         * Paths must be absolute filesystem paths. They are resolved with
         * realpath() and every discovered file is verified to stay inside them.
         *
         * @param array<int, string> $paths Absolute directory paths, most specific first.
         */
        $filtered = apply_filters('jotform_bridge_template_paths', array_keys($candidates));

        if (is_array($filtered)) {
            foreach ($filtered as $path) {
                if (!is_string($path) || trim($path) === '') {
                    continue;
                }

                if (!isset($candidates[$path])) {
                    $candidates[$path] = self::SOURCE_FILTER;
                }
            }
        }

        $roots = [];
        $seen  = [];

        foreach ($candidates as $path => $source) {
            $real = realpath((string) $path);

            if ($real === false || !is_dir($real) || !is_readable($real) || isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;
            $roots[]     = [
                'path'   => $real,
                'source' => $source,
            ];
        }

        return $roots;
    }

    /**
     * PHP files directly inside one root, verified to actually live there.
     *
     * The scan is deliberately shallow: nested partials are an implementation
     * detail of a template, not templates themselves.
     *
     * @param array<int, array<string, string>> $diagnostics
     *
     * @return array<int, string> Absolute, resolved file paths.
     */
    private function phpFiles(string $root, array &$diagnostics): array
    {
        $entries = @scandir($root);

        if ($entries === false) {
            $diagnostics[] = [
                'level'   => self::LEVEL_WARNING,
                'code'    => self::CODE_UNREADABLE,
                'file'    => $root,
                'slug'    => '',
                'message' => sprintf(
                    /* translators: %s: directory path */
                    __('The template directory "%s" could not be read.', 'jotform-bridge'),
                    $root
                ),
            ];

            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strncmp($entry, '.', 1) === 0) {
                continue;
            }

            if (strtolower((string) pathinfo($entry, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $real = realpath($root . DIRECTORY_SEPARATOR . $entry);

            if ($real === false || !is_file($real) || !is_readable($real)) {
                continue;
            }

            // A symlink may resolve outside the root it was found in. Such a
            // file is not covered by the trusted directory and is rejected.
            if (strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
                $diagnostics[] = [
                    'level'   => self::LEVEL_ERROR,
                    'code'    => self::CODE_OUTSIDE_ROOT,
                    'file'    => $root . DIRECTORY_SEPARATOR . $entry,
                    'slug'    => '',
                    'message' => sprintf(
                        /* translators: %s: file name */
                        __('"%s" resolves outside its template directory and was ignored.', 'jotform-bridge'),
                        $entry
                    ),
                ];

                continue;
            }

            $files[] = $real;
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<int, array<string, string>> $diagnostics
     *
     * @return array<string, mixed>|null Null when the file is not a valid template.
     */
    private function readTemplate(string $file, string $source, array &$diagnostics): ?array
    {
        $header = $this->read($file, self::HEADER_BYTES);

        if ($header === '') {
            return null;
        }

        $name    = $this->headerValue($header, self::HEADER_NAME);
        $rawSlug = $this->headerValue($header, self::HEADER_SLUG);

        // Not a Jotform template at all — an ordinary theme file in the same
        // directory is not an error.
        if ($name === '' && $rawSlug === '') {
            return null;
        }

        $shortName = basename($file);

        if ($this->headerValue($header, self::HEADER_FORBIDDEN) !== '') {
            $diagnostics[] = [
                'level'   => self::LEVEL_ERROR,
                'code'    => self::CODE_FORBIDDEN,
                'file'    => $file,
                'slug'    => $rawSlug,
                'message' => sprintf(
                    /* translators: 1: file name, 2: forbidden header name */
                    __(
                        '"%1$s" declares "%2$s". A template must not name a Jotform form — that binding belongs to the integration.',
                        'jotform-bridge'
                    ),
                    $shortName,
                    self::HEADER_FORBIDDEN
                ),
            ];

            return null;
        }

        if ($name === '') {
            $diagnostics[] = [
                'level'   => self::LEVEL_ERROR,
                'code'    => self::CODE_MISSING_NAME,
                'file'    => $file,
                'slug'    => $rawSlug,
                'message' => sprintf(
                    /* translators: 1: file name, 2: header name */
                    __('"%1$s" is missing the "%2$s" header.', 'jotform-bridge'),
                    $shortName,
                    self::HEADER_NAME
                ),
            ];

            return null;
        }

        if ($rawSlug === '') {
            $diagnostics[] = [
                'level'   => self::LEVEL_ERROR,
                'code'    => self::CODE_MISSING_SLUG,
                'file'    => $file,
                'slug'    => '',
                'message' => sprintf(
                    /* translators: 1: file name, 2: header name */
                    __('"%1$s" is missing the "%2$s" header.', 'jotform-bridge'),
                    $shortName,
                    self::HEADER_SLUG
                ),
            ];

            return null;
        }

        $slug = sanitize_key($rawSlug);

        if ($slug === '') {
            $diagnostics[] = [
                'level'   => self::LEVEL_ERROR,
                'code'    => self::CODE_INVALID_SLUG,
                'file'    => $file,
                'slug'    => $rawSlug,
                'message' => sprintf(
                    /* translators: 1: file name, 2: declared slug */
                    __('The slug "%2$s" declared in "%1$s" contains no usable characters.', 'jotform-bridge'),
                    $shortName,
                    $rawSlug
                ),
            ];

            return null;
        }

        if ($slug !== $rawSlug) {
            $diagnostics[] = [
                'level'   => self::LEVEL_WARNING,
                'code'    => self::CODE_NORMALIZED_SLUG,
                'file'    => $file,
                'slug'    => $slug,
                'message' => sprintf(
                    /* translators: 1: declared slug, 2: normalized slug */
                    __('The slug "%1$s" was normalized to "%2$s".', 'jotform-bridge'),
                    $rawSlug,
                    $slug
                ),
            ];
        }

        $discovered = $this->fields($file);

        if ($discovered['dynamic'] > 0) {
            $diagnostics[] = [
                'level'   => self::LEVEL_WARNING,
                'code'    => self::CODE_DYNAMIC_FIELD,
                'file'    => $file,
                'slug'    => $slug,
                'message' => sprintf(
                    /* translators: 1: number of identifiers, 2: file name */
                    _n(
                        '%1$d dynamic field identifier in "%2$s" cannot be statically validated.',
                        '%1$d dynamic field identifiers in "%2$s" cannot be statically validated.',
                        $discovered['dynamic'],
                        'jotform-bridge'
                    ),
                    $discovered['dynamic'],
                    $shortName
                ),
            ];
        }

        if ($discovered['fields'] === [] && $discovered['dynamic'] === 0) {
            $diagnostics[] = [
                'level'   => self::LEVEL_WARNING,
                'code'    => self::CODE_NO_FIELDS,
                'file'    => $file,
                'slug'    => $slug,
                'message' => sprintf(
                    /* translators: %s: file name */
                    __('"%s" contains no data-jotform-field identifiers.', 'jotform-bridge'),
                    $shortName
                ),
            ];
        }

        return [
            'slug'    => $slug,
            'name'    => $name,
            'file'    => $file,
            'source'  => $source,
            'fields'  => $discovered['fields'],
            'dynamic' => $discovered['dynamic'],
            'mtime'   => (int) @filemtime($file),
        ];
    }

    /**
     * @param array<string, mixed> $kept
     * @param array<string, mixed> $rejected
     *
     * @return array<string, string>
     */
    private function duplicateDiagnostic(array $kept, array $rejected, int $priority): array
    {
        $sameRoot = ($kept['priority'] ?? -1) === $priority;

        if (!$sameRoot) {
            // The expected child-theme override: informational, not a problem.
            return [
                'level'   => self::LEVEL_NOTICE,
                'code'    => self::CODE_OVERRIDDEN,
                'file'    => (string) $rejected['file'],
                'slug'    => (string) $rejected['slug'],
                'message' => sprintf(
                    /* translators: 1: template slug, 2: winning file path */
                    __('The template "%1$s" is overridden by "%2$s".', 'jotform-bridge'),
                    (string) $rejected['slug'],
                    (string) $kept['file']
                ),
            ];
        }

        return [
            'level'   => self::LEVEL_ERROR,
            'code'    => self::CODE_DUPLICATE,
            'file'    => (string) $rejected['file'],
            'slug'    => (string) $rejected['slug'],
            'message' => sprintf(
                /* translators: 1: template slug, 2: ignored file, 3: used file */
                __(
                    'The slug "%1$s" is declared twice in the same directory. "%2$s" was ignored in favour of "%3$s".',
                    'jotform-bridge'
                ),
                (string) $rejected['slug'],
                basename((string) $rejected['file']),
                basename((string) $kept['file'])
            ),
        ];
    }

    /**
     * Extracts the literal semantic identifiers a template declares.
     *
     * Values produced by PHP are reported as dynamic instead of guessed: a
     * wrong guess would either hide a real incompatibility or invent one.
     *
     * @return array{fields: array<int, string>, dynamic: int}
     */
    public function fields(string $file): array
    {
        $source = $this->read($file, self::SOURCE_BYTES);

        if ($source === '') {
            return ['fields' => [], 'dynamic' => 0];
        }

        $fields  = [];
        $dynamic = 0;

        // Quoted attribute values, single or double quoted.
        if (preg_match_all('/data-jotform-field\s*=\s*(["\'])(.*?)\1/s', $source, $matches) !== false) {
            foreach ($matches[2] as $value) {
                if (strpos($value, '<?') !== false) {
                    ++$dynamic;

                    continue;
                }

                $value = trim($value);

                if ($value !== '') {
                    $fields[$value] = true;
                }
            }
        }

        // Unquoted attribute values: data-jotform-field=email
        if (preg_match_all('/data-jotform-field\s*=\s*([^"\'\s>]+)/', $source, $matches) !== false) {
            foreach ($matches[1] as $value) {
                if (strpos($value, '<?') !== false) {
                    ++$dynamic;

                    continue;
                }

                $value = trim($value);

                if ($value !== '') {
                    $fields[$value] = true;
                }
            }
        }

        $keys = array_keys($fields);
        sort($keys);

        return [
            'fields'  => $keys,
            'dynamic' => $dynamic,
        ];
    }

    /**
     * Reads at most $bytes from a file. The file is never included or evaluated.
     */
    private function read(string $file, int $bytes): string
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        $contents = @fread($handle, $bytes);

        fclose($handle);

        return is_string($contents) ? $contents : '';
    }

    /**
     * Reads one `Header Name: value` line out of a file header.
     */
    private function headerValue(string $header, string $field): string
    {
        $pattern = '/^[ \t\/*#@]*' . preg_quote($field, '/') . ':(.*)$/mi';

        if (preg_match($pattern, $header, $match) !== 1) {
            return '';
        }

        $value = trim((string) preg_replace('/\s*(?:\*\/|\?>).*$/', '', $match[1]));

        return trim($value);
    }
}
