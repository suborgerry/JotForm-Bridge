<?php

/**
 * @var array<int, string> $conditionErrors
 * @var array<int, array<string, string>> $conditionRows
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Integrations\ConditionalLogic;

if (!defined('ABSPATH')) {
    exit;
}
?>
<h2><?php echo esc_html__('Conditional logic', 'jotform-bridge'); ?></h2>
<p class="description">
    <?php echo esc_html__('Saved rules are read-only. They continue to control field visibility and requirements. Saving this integration does not change them.', 'jotform-bridge'); ?>
</p>
<?php foreach ($conditionErrors as $jfbError) : ?>
    <p class="jfb-state-error"><?php echo esc_html($jfbError); ?></p>
<?php endforeach; ?>
<?php if ($conditionRows === []) : ?>
    <p><?php echo esc_html__('No saved conditional rules.', 'jotform-bridge'); ?></p>
<?php else : ?>
    <div class="jfb-table-scroll">
        <table class="widefat striped" data-jfb-rules-readonly>
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Target field', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Action', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Source field', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Comparison', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Value', 'jotform-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conditionRows as $jfbRule) : ?>
                    <tr>
                        <td><code><?php echo esc_html($jfbRule['target']); ?></code></td>
                        <td><?php echo esc_html(ConditionalLogic::actions()[$jfbRule['action']] ?? $jfbRule['action']); ?></td>
                        <td><code><?php echo esc_html($jfbRule['source']); ?></code></td>
                        <td><?php echo esc_html(ConditionalLogic::operators()[$jfbRule['operator']] ?? $jfbRule['operator']); ?></td>
                        <td><?php echo esc_html(in_array($jfbRule['operator'], ['empty', 'not_empty'], true) ? '—' : $jfbRule['value']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
