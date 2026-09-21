<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Discovers custom form templates by reading — never executing — theme files.
 *
 * discover() reads only file headers; fields() reads a whole file and is used
 * by the admin compatibility report only. Paths come from the theme directories
 * and the `jotform_bridge_template_paths` filter, never from a request.
 */
final class TemplateScanner
{
    public const HEADER_NAME = 'Jotform Template Name';

    /** Legacy header; ignored with a warning, the slug is the file name. */
    public const HEADER_SLUG = 'Jotform Template Slug';

    /** Rejected: the form binding belongs to the Integration. */
    public const HEADER_FORBIDDEN = 'Jotform Form ID';

    public const DIRECTORY = 'jotform-bridge-templates';

    public const SOURCE_CHILD_THEME  = 'child_theme';
    public const SOURCE_PARENT_THEME = 'parent_theme';
    public const SOURCE_THEME        = 'theme';
    public const SOURCE_FILTER       = 'filter';

    public const LEVEL_ERROR   = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_NOTICE  = 'notice';

    public const CODE_MISSING_NAME    = 'missing_name';
    public const CODE_INVALID_SLUG    = 'invalid_slug';
    public const CODE_IGNORED_SLUG    = 'ignored_slug';
    public const CODE_FORBIDDEN       = 'forbidden_header';
    public const CODE_OVERRIDDEN      = 'template_overridden';
    public const CODE_UNREADABLE      = 'unreadable_file';
    public const CODE_OUTSIDE_ROOT    = 'outside_template_root';

    /** Bytes read for a header. */
    private const HEADER_BYTES = 8192;

    /** Upper bound for the source searched for field identifiers. */
    private const SOURCE_BYTES = 262144;

    /**
     * Lists the templates on disk, from headers only.
     *
     * @return array{
     *     templates: array<string, array<string, mixed>>,
     *     diagnostics: array<int, array<string, string>>,
     *     roots: array<int, array{path:string, source:string}>
     * }
     */
    public function discover(): array
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
                    $diagnostics[] = $this->overrideDiagnostic($templates[$slug], $template);

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
     * PHP files directly inside one root (no recursion), verified to resolve there.
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

            // A symlink resolving outside the root is rejected.
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

        $name         = $this->headerValue($header, self::HEADER_NAME);
        $declaredSlug = $this->headerValue($header, self::HEADER_SLUG);

        // An ordinary theme file in the directory is not an error.
        if ($name === '' && $declaredSlug === '') {
            return null;
        }

        $shortName = basename($file);

        if ($this->headerValue($header, self::HEADER_FORBIDDEN) !== '') {
            $diagnostics[] = [
                'level'   => self::LEVEL_ERROR,
                'code'    => self::CODE_FORBIDDEN,
                'file'    => $file,
                'slug'    => '',
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
                'slug'    => '',
                'message' => sprintf(
                    /* translators: 1: file name, 2: header name */
                    __('"%1$s" is missing the "%2$s" header.', 'jotform-bridge'),
                    $shortName,
                    self::HEADER_NAME
                ),
            ];

            return null;
        }

        $slug = sanitize_key(basename($file, '.php'));

        if ($slug === '') {
            $diagnostics[] = [
                'level'   => self::LEVEL_ERROR,
                'code'    => self::CODE_INVALID_SLUG,
                'file'    => $file,
                'slug'    => '',
                'message' => sprintf(
                    /* translators: %s: file name */
                    __('The name of the file "%s" holds no characters usable as a slug.', 'jotform-bridge'),
                    $shortName
                ),
            ];

            return null;
        }

        if ($declaredSlug !== '') {
            $diagnostics[] = [
                'level'   => self::LEVEL_WARNING,
                'code'    => self::CODE_IGNORED_SLUG,
                'file'    => $file,
                'slug'    => $slug,
                'message' => sprintf(
                    /* translators: 1: file name, 2: header name, 3: slug taken from the file name */
                    __(
                        '"%1$s" declares "%2$s". The header is ignored: the slug is the file name, so this template is "%3$s".',
                        'jotform-bridge'
                    ),
                    $shortName,
                    self::HEADER_SLUG,
                    $slug
                ),
            ];
        }

        return [
            'slug'   => $slug,
            'name'   => $name,
            'file'   => $file,
            'source' => $source,
            'mtime'  => $this->lastChange($file),
        ];
    }

    /** Later of mtime and ctime: tools that preserve mtime still move ctime. */
    private function lastChange(string $file): int
    {
        $mtime = (int) @filemtime($file);
        $ctime = (int) @filectime($file);

        return max($mtime, $ctime);
    }


    /**
     * @param array<string, mixed> $kept
     * @param array<string, mixed> $rejected
     *
     * @return array<string, string>
     */
    private function overrideDiagnostic(array $kept, array $rejected): array
    {
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

    /**
     * Extracts the literal `data-jotform-field` identifiers a template declares;
     * values produced by PHP are counted as dynamic.
     *
     * @return array{fields: array<int, string>, dynamic: int}
     */
    public function fields(string $file): array
    {
        $source = $this->read($file, self::SOURCE_BYTES);

        if ($source === '') {
            return ['fields' => [], 'dynamic' => 0];
        }

        $source = self::withoutPhpComments($source);

        $fields  = [];
        $dynamic = 0;

        // Quoted attribute values.
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

    /** Removes PHP comments (not HTML comments) from a template source. */
    private static function withoutPhpComments(string $source): string
    {
        if (strpos($source, '<?') === false) {
            return $source;
        }

        // The source may be truncated, so an unterminated token is expected.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unterminated token is the normal case here, and the return value is checked below.
        $tokens = @token_get_all($source);

        if (!is_array($tokens) || $tokens === []) {
            return $source;
        }

        $clean = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep the newlines.
                    $clean .= str_repeat("\n", substr_count((string) $token[1], "\n"));

                    continue;
                }

                $clean .= (string) $token[1];

                continue;
            }

            $clean .= (string) $token;
        }

        return $clean;
    }

    /**
     * Reads at most $bytes from a file through plain fopen(); the file is never
     * included. Errors are suppressed: an unreadable file is handled by the
     * return value.
     *
     * @param positive-int $bytes
     */
    private function read(string $file, int $bytes): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- see the docblock above.
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.PHP.NoSilencedErrors.Discouraged -- see the docblock above.
        $contents = @fread($handle, $bytes);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see the docblock above.
        fclose($handle);

        return is_string($contents) ? $contents : '';
    }

    /** Reads one `Header Name: value` line out of a file header. */
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
