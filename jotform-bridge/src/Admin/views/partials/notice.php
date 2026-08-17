<?php

/**
 * One-shot admin notice, optionally with a detail list.
 *
 * @var array<string, mixed>|null $notice
 *
 * @package JotformBridge
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($notice) || !is_array($notice)) {
    return;
}

$jfbType = isset($notice['type']) ? (string) $notice['type'] : 'info';
$jfbType = in_array($jfbType, ['success', 'error', 'warning', 'info'], true) ? $jfbType : 'info';
?>
<div class="notice notice-<?php echo esc_attr($jfbType); ?> is-dismissible">
    <p><?php echo esc_html((string) $notice['message']); ?></p>

    <?php if (!empty($notice['messages']) && is_array($notice['messages'])) : ?>
        <ul class="ul-disc">
            <?php foreach ($notice['messages'] as $jfbDetail) : ?>
                <li><?php echo esc_html((string) $jfbDetail); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
