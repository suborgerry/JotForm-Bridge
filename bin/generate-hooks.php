<?php

/**
 * Builds docs/hooks.md from the docblocks above the plugin's own hook calls.
 *
 * Every filter and action is documented next to the apply_filters() or
 * do_action() that fires it, and nowhere else. That is the right place for the
 * person editing the code and the wrong place for the person writing a theme:
 * the only way to discover the extension points was to read src/, which in
 * practice meant they were not discovered.
 *
 * This script exists rather than a hand-written reference because a
 * hand-written one drifts. docs/hooks.md is generated output — edit the docblock,
 * not the Markdown.
 *
 * Usage:
 *   php bin/generate-hooks.php           Write docs/hooks.md.
 *   php bin/generate-hooks.php --check   Exit 1 if docs/hooks.md is out of date.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

$root   = dirname(__DIR__);
$target = $root . '/docs/hooks.md';
$check  = in_array('--check', array_slice($argv, 1), true);

$sources = collectSources($root . '/jotform-bridge');
$hooks   = [];

foreach ($sources as $file) {
    foreach (findHooks($file, $root) as $hook) {
        $name = $hook['name'];

        if (isset($hooks[$name])) {
            $hooks[$name]['sites'] = array_merge($hooks[$name]['sites'], $hook['sites']);

            // A second call site with a docblock wins over one without.
            if ($hooks[$name]['summary'] === '' && $hook['summary'] !== '') {
                $hooks[$name]['summary']     = $hook['summary'];
                $hooks[$name]['description'] = $hook['description'];
                $hooks[$name]['params']      = $hook['params'];
            }

            continue;
        }

        $hooks[$name] = $hook;
    }
}

ksort($hooks);

$undocumented = array_keys(array_filter($hooks, static fn(array $h): bool => $h['summary'] === ''));

if ($undocumented !== []) {
    fwrite(STDERR, "error: these hooks have no docblock above the call:\n");

    foreach ($undocumented as $name) {
        fwrite(STDERR, '  ' . $name . "\n");
    }

    exit(1);
}

$markdown = render($hooks);

if ($check) {
    $current = is_file($target) ? file_get_contents($target) : '';

    if ($current === $markdown) {
        echo "docs/hooks.md is up to date (" . count($hooks) . " hooks).\n";
        exit(0);
    }

    fwrite(STDERR, "error: docs/hooks.md is out of date. Run: composer hooks\n");
    exit(1);
}

file_put_contents($target, $markdown);
echo 'Wrote docs/hooks.md (' . count($hooks) . " hooks).\n";

/**
 * @return array<int, string>
 */
function collectSources(string $pluginDir): array
{
    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir . '/src', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    $files[] = $pluginDir . '/jotform-bridge.php';

    sort($files);

    return $files;
}

/**
 * Every apply_filters()/do_action() call in one file, with the docblock above it.
 *
 * @return array<int, array<string, mixed>>
 */
function findHooks(string $file, string $root): array
{
    $source = (string) file_get_contents($file);
    $tokens = token_get_all($source);
    $consts = classConstants($tokens);
    $found  = [];
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $kind = match ($token[1]) {
            'apply_filters', 'apply_filters_ref_array' => 'filter',
            'do_action', 'do_action_ref_array'         => 'action',
            default                                    => null,
        };

        if ($kind === null || isMethodCall($tokens, $i)) {
            continue;
        }

        $name = hookName($tokens, $i, $consts);

        if ($name === null) {
            continue;
        }

        $doc = docblockAbove($tokens, $i);

        $found[] = [
            'name'        => $name,
            'kind'        => $kind,
            'summary'     => $doc['summary'],
            'description' => $doc['description'],
            'params'      => $doc['params'],
            'sites'       => [
                [
                    'file' => ltrim(str_replace($root, '', $file), '/'),
                    'line' => $token[2],
                ],
            ],
        ];
    }

    return $found;
}

/**
 * `->foo()` and `::foo()` are not the WordPress functions we are looking for.
 *
 * @param array<int, mixed> $tokens
 */
function isMethodCall(array $tokens, int $index): bool
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            continue;
        }

        return is_array($tokens[$i])
            && in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
    }

    return false;
}

/**
 * The first argument, whether it is a literal or a class constant.
 *
 * @param array<int, mixed>     $tokens
 * @param array<string, string> $consts
 */
function hookName(array $tokens, int $index, array $consts): ?string
{
    $i     = $index + 1;
    $count = count($tokens);

    while ($i < $count && $tokens[$i] !== '(') {
        $i++;
    }

    $args = [];

    for ($i++; $i < $count && count($args) < 3; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        if ($token === ',') {
            break;
        }

        $args[] = $token;
    }

    if ($args === []) {
        return null;
    }

    if (is_array($args[0]) && $args[0][0] === T_CONSTANT_ENCAPSED_STRING) {
        return trim($args[0][1], "'\"");
    }

    // self::FILTER, static::FILTER, ClassName::FILTER — resolved from the same
    // file, which is where every one of ours is declared.
    if (count($args) === 3 && is_array($args[2]) && $args[2][0] === T_STRING) {
        return $consts[$args[2][1]] ?? null;
    }

    return null;
}

/**
 * Class constants declared in this file, so `self::FILTER` can be resolved.
 *
 * @param array<int, mixed> $tokens
 *
 * @return array<string, string>
 */
function classConstants(array $tokens): array
{
    $consts = [];
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
            continue;
        }

        $name  = null;
        $value = null;

        for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
            $token = $tokens[$j];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING && $name === null) {
                $name = $token[1];
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && $value === null) {
                $value = trim($token[1], "'\"");
            }
        }

        if ($name !== null && $value !== null) {
            $consts[$name] = $value;
        }
    }

    return $consts;
}

/**
 * The docblock immediately above the statement containing the call.
 *
 * Walks back over the assignment and any wrapping cast or call, and stops at
 * the first thing that ends a statement — so an unrelated docblock further up
 * is never picked up by mistake.
 *
 * @param array<int, mixed> $tokens
 *
 * @return array{summary: string, description: string, params: array<int, array<string, string>>}
 */
function docblockAbove(array $tokens, int $index): array
{
    $empty = ['summary' => '', 'description' => '', 'params' => []];

    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ($token === ';' || $token === '{' || $token === '}') {
                return $empty;
            }

            continue;
        }

        if ($token[0] === T_DOC_COMMENT) {
            return parseDocblock($token[1]);
        }

        // A line comment between the docblock and the call — a phpcs:ignore,
        // say — does not detach the two.
        if ($token[0] === T_COMMENT) {
            continue;
        }

        if (in_array($token[0], [T_CLOSE_TAG, T_OPEN_TAG], true)) {
            return $empty;
        }
    }

    return $empty;
}

/**
 * @return array{summary: string, description: string, params: array<int, array<string, string>>}
 */
function parseDocblock(string $block): array
{
    $lines = preg_split('/\R/', $block) ?: [];
    $body  = [];

    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('#^/\*\*+#', '', $line) ?? $line;
        $line = preg_replace('#\*/$#', '', $line) ?? $line;
        $line = preg_replace('#^\*\s?#', '', $line) ?? $line;
        $body[] = rtrim($line);
    }

    $summary     = '';
    $description = [];
    $params      = [];

    foreach ($body as $line) {
        if (str_starts_with($line, '@param')) {
            $param = parseParam($line);

            if ($param !== null) {
                $params[] = $param;
            }

            continue;
        }

        if (str_starts_with($line, '@')) {
            continue;
        }

        if ($summary === '') {
            $summary = trim($line);
            continue;
        }

        $description[] = $line;
    }

    return [
        'summary'     => $summary,
        'description' => trim(implode("\n", $description)),
        'params'      => $params,
    ];
}

/**
 * `@param array<int, string> $paths Absolute directory paths.`
 *
 * @return array<string, string>|null
 */
function parseParam(string $line): ?array
{
    $rest = trim(substr($line, strlen('@param')));

    // The type may itself contain spaces inside <>, so the variable is found
    // rather than assumed to be the second word.
    if (!preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $rest, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $offset = (int) $match[0][1];

    return [
        'name'        => '$' . $match[1][0],
        'type'        => trim(substr($rest, 0, $offset)),
        'description' => trim(substr($rest, $offset + strlen($match[0][0]))),
    ];
}

/**
 * @param array<string, array<string, mixed>> $hooks
 */
function render(array $hooks): string
{
    $filters = array_filter($hooks, static fn(array $h): bool => $h['kind'] === 'filter');
    $actions = array_filter($hooks, static fn(array $h): bool => $h['kind'] === 'action');

    $out = <<<'MD'
    # Hooks

    Every extension point Jotform Bridge provides. A theme or a companion plugin
    can change what the plugin does through these and through nothing else — the
    plugin's classes are `final` on purpose.

    > **Generated file.** Built from the docblocks above the `apply_filters()`
    > and `do_action()` calls in `jotform-bridge/src/` by
    > `bin/generate-hooks.php`. Edit the docblock, then run `composer hooks`.
    > Editing this file directly will be overwritten, and CI checks that it
    > matches the source.

    MD;

    $out .= "\n";
    $out .= '**' . count($filters) . '** filters, **' . count($actions) . '** actions.' . "\n\n";
    $out .= "---\n\n";

    if ($filters !== []) {
        $out .= "# Filters\n\nA filter receives a value and must return one. Returning nothing, or a\nvalue of the wrong type, is treated as no opinion: the plugin falls back to\nwhat it would have used anyway.\n\n";
        $out .= renderSection($filters);
    }

    if ($actions !== []) {
        $out .= "# Actions\n\nAn action's return value is ignored. Anything slow belongs on a queue: these\nfire inside the request the visitor is waiting on.\n\n";
        $out .= renderSection($actions);
    }

    return $out;
}

/**
 * @param array<string, array<string, mixed>> $hooks
 */
function renderSection(array $hooks): string
{
    $out = '';

    foreach ($hooks as $name => $hook) {
        $out .= '## `' . $name . "`\n\n";
        $out .= $hook['summary'] . "\n\n";

        if ($hook['description'] !== '') {
            $out .= $hook['description'] . "\n\n";
        }

        if ($hook['params'] !== []) {
            $out .= "| Parameter | Type | Description |\n";
            $out .= "| --- | --- | --- |\n";

            foreach ($hook['params'] as $param) {
                $out .= '| `' . $param['name'] . '` '
                    . '| `' . str_replace('|', '\|', $param['type']) . '` '
                    . '| ' . ($param['description'] !== '' ? $param['description'] : '—') . " |\n";
            }

            $out .= "\n";
        }

        $sites = array_map(
            static fn(array $site): string => '`' . $site['file'] . ':' . $site['line'] . '`',
            $hook['sites']
        );

        $out .= 'Fires in ' . implode(', ', $sites) . ".\n\n";
    }

    return $out;
}
