<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Templates\TemplateRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders a registered theme template by slug; the file always comes from
 * TemplateRegistry, never from a path.
 */
final class CustomTemplateRenderer
{
    private TemplateRegistry $templates;

    public function __construct(TemplateRegistry $templates)
    {
        $this->templates = $templates;
    }

    /**
     * @param array<string, mixed> $context Variables extracted into the template scope.
     *
     * @return string|null Null when the slug has no renderable file.
     */
    public function render(string $slug, array $context): ?string
    {
        $file = $this->templates->file($slug);

        if ($file === null) {
            return null;
        }

        $level = ob_get_level();

        ob_start();

        try {
            self::includeTemplate($file, $context);
        } catch (\Throwable $error) {
            // Discard the partial output.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $error;
        }

        return (string) ob_get_clean();
    }

    /**
     * Includes the file in an isolated scope; static, so the template cannot reach `$this`.
     *
     * @param array<string, mixed> $jotform_bridge_context
     */
    private static function includeTemplate(string $jotform_bridge_file, array $jotform_bridge_context): void
    {
        extract($jotform_bridge_context, EXTR_SKIP);

        require $jotform_bridge_file;
    }
}
