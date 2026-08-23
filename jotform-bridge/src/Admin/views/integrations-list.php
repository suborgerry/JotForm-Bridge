<?php

/**
 * Integrations list screen.
 *
 * @var array<string, array{integration:\JotformBridge\Integrations\Integration, compatibility:array<string,mixed>, redirect:array<string,mixed>, form_title:string, schema_meta:array<string,mixed>|null, schema_synced:bool, schema_stale:bool}> $rows
 * @var \JotformBridge\Templates\TemplateRegistry $templates
 * @var array<int, array<string, string>>         $diagnostics
 * @var array<string, mixed>|null                 $notice
 * @var string                                    $page
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Integrations\RedirectTarget;
use JotformBridge\Templates\TemplateScanner;

if (!defined('ABSPATH')) {
    exit;
}

$jfbNewUrl = add_query_arg(['page' => $page, 'view' => 'new'], admin_url('admin.php'));
?>
<div class="wrap jfb-integrations">
    <h1 class="wp-heading-inline"><?php echo esc_html__('Integrations', 'jotform-bridge'); ?></h1>
    <a href="<?php echo esc_url($jfbNewUrl); ?>" class="page-title-action">
        <?php echo esc_html__('Add New', 'jotform-bridge'); ?>
    </a>
    <hr class="wp-header-end">

    <?php require __DIR__ . '/partials/notice.php'; ?>

    <p class="description">
        <?php
        echo esc_html__(
            'An integration is the local name your theme uses. Theme code never needs a Jotform form ID.',
            'jotform-bridge'
        );
        ?>
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php echo esc_html__('Name', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Slug', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Jotform Form', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Rendering Mode', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Template', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Active', 'jotform-bridge'); ?></th>
                <?php if ($showStats) : ?>
                    <th scope="col"><?php echo esc_html__('Last 7 days', 'jotform-bridge'); ?></th>
                <?php endif; ?>
                <th scope="col"><?php echo esc_html__('Schema', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Compatibility', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Redirect target', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Actions', 'jotform-bridge'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []) : ?>
                <tr>
                    <td colspan="<?php echo $showStats ? 11 : 10; ?>">
                        <?php echo esc_html__('No integrations yet. Add one to connect a Jotform form to a template.', 'jotform-bridge'); ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($rows as $jfbRow) : ?>
                <?php
                $jfbIntegration = $jfbRow['integration'];
                $jfbEditUrl     = add_query_arg(
                    [
                        'page'        => $page,
                        'view'        => 'edit',
                        'integration' => $jfbIntegration->slug(),
                    ],
                    admin_url('admin.php')
                );
                ?>
                <tr>
                    <td>
                        <strong>
                            <a href="<?php echo esc_url($jfbEditUrl); ?>">
                                <?php echo esc_html($jfbIntegration->name()); ?>
                            </a>
                        </strong>
                    </td>
                    <td><code><?php echo esc_html($jfbIntegration->slug()); ?></code></td>
                    <td>
                        <?php if ($jfbRow['form_title'] !== '') : ?>
                            <?php echo esc_html($jfbRow['form_title']); ?>
                        <?php else : ?>
                            <span class="description">
                                <?php echo esc_html__('Not in the stored form list', 'jotform-bridge'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($jfbIntegration->modeLabel()); ?></td>
                    <td>
                        <?php if ($jfbIntegration->templateSlug() !== '') : ?>
                            <code><?php echo esc_html($jfbIntegration->templateSlug()); ?></code>
                        <?php else : ?>
                            <span class="description">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        echo $jfbIntegration->isActive()
                            ? esc_html__('Yes', 'jotform-bridge')
                            : esc_html__('No', 'jotform-bridge');
                        ?>
                    </td>
                    <?php if ($showStats) : ?>
                    <td>
                        <?php
                        $jfbStats  = $jfbRow['stats'];
                        $jfbHealth = (string) $jfbRow['health'];
                        ?>
                        <?php if ($jfbStats['attempts'] === 0) : ?>
                            <span class="description"><?php echo esc_html__('No submissions', 'jotform-bridge'); ?></span>
                        <?php else : ?>
                            <?php
                            printf(
                                /* translators: 1: accepted submissions, 2: submissions that did not get through */
                                esc_html__('%1$d sent, %2$d not delivered', 'jotform-bridge'),
                                (int) $jfbStats['ok'],
                                (int) $jfbStats['lost']
                            );
                            ?>
                            <?php if ($jfbRow['last_ok'] > 0) : ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: human readable time difference */
                                        esc_html__('Last one %s ago', 'jotform-bridge'),
                                        esc_html(human_time_diff((int) $jfbRow['last_ok'], time()))
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($jfbHealth === 'broken') : ?>
                            <p><strong style="color:#b32d2e;">
                                <?php echo esc_html__('Nothing got through today.', 'jotform-bridge'); ?>
                            </strong></p>
                        <?php elseif ($jfbHealth === 'noisy') : ?>
                            <p><strong style="color:#996800;">
                                <?php echo esc_html__('Almost everything is being refused today.', 'jotform-bridge'); ?>
                            </strong></p>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php if (!$jfbRow['schema_synced']) : ?>
                            <strong style="color:#b32d2e;">
                                <?php echo esc_html__('Not synced', 'jotform-bridge'); ?>
                            </strong>
                        <?php else : ?>
                            <?php
                            $jfbSyncedAt = $jfbRow['schema_meta'] !== null ? (int) $jfbRow['schema_meta']['synced_at'] : 0;

                            if ($jfbSyncedAt > 0) {
                                printf(
                                    /* translators: %s: human readable time difference */
                                    esc_html__('Synced %s ago', 'jotform-bridge'),
                                    esc_html(human_time_diff($jfbSyncedAt, time()))
                                );
                            } else {
                                echo esc_html__('Synced', 'jotform-bridge');
                            }
                            ?>
                            <?php if ($jfbRow['schema_stale']) : ?>
                                <p class="description">
                                    <?php echo esc_html__('Synced by an older plugin version.', 'jotform-bridge'); ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($jfbRow['compatibility']['label']); ?></td>
                    <td>
                        <?php echo esc_html((string) $jfbRow['redirect']['label']); ?>
                        <?php if (RedirectTarget::isBroken($jfbRow['redirect'])) : ?>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html((string) $jfbRow['redirect']['message']); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url($jfbEditUrl); ?>"><?php echo esc_html__('Edit', 'jotform-bridge'); ?></a>
                        |
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_SYNC); ?>">
                            <input type="hidden" name="integration" value="<?php echo esc_attr($jfbIntegration->slug()); ?>">
                            <?php wp_nonce_field(IntegrationsPage::ACTION_SYNC); ?>
                            <button type="submit" class="button-link">
                                <?php echo esc_html__('Sync schema', 'jotform-bridge'); ?>
                            </button>
                        </form>
                        |
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_TOGGLE); ?>">
                            <input type="hidden" name="integration" value="<?php echo esc_attr($jfbIntegration->slug()); ?>">
                            <input type="hidden" name="active" value="<?php echo $jfbIntegration->isActive() ? '0' : '1'; ?>">
                            <?php wp_nonce_field(IntegrationsPage::ACTION_TOGGLE); ?>
                            <button type="submit" class="button-link">
                                <?php
                                echo $jfbIntegration->isActive()
                                    ? esc_html__('Deactivate', 'jotform-bridge')
                                    : esc_html__('Activate', 'jotform-bridge');
                                ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php echo esc_html__('Templates', 'jotform-bridge'); ?></h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_RESCAN); ?>">
        <?php wp_nonce_field(IntegrationsPage::ACTION_RESCAN); ?>
        <?php submit_button(__('Rescan Templates', 'jotform-bridge'), 'secondary', 'submit', false); ?>
    </form>

    <p class="description">
        <?php
        $jfbRoots = [];

        foreach ($templates->roots() as $jfbRoot) {
            $jfbRoots[] = (string) $jfbRoot['path'];
        }

        if ($jfbRoots === []) {
            echo esc_html__('No template directory exists yet. Create a /forms/ directory in your theme.', 'jotform-bridge');
        } else {
            printf(
                /* translators: %s: list of scanned directories */
                esc_html__('Scanned directories: %s', 'jotform-bridge'),
                '<code>' . implode('</code>, <code>', array_map('esc_html', $jfbRoots)) . '</code>'
            );
        }
        ?>
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php echo esc_html__('Template', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Slug', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Source', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Fields', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('File', 'jotform-bridge'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($templates->isEmpty()) : ?>
                <tr>
                    <td colspan="5">
                        <?php echo esc_html__('No templates found. Add a PHP file with a Jotform template header to your theme /forms/ directory.', 'jotform-bridge'); ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($templates->all() as $jfbTemplate) : ?>
                <tr>
                    <td><strong><?php echo esc_html((string) $jfbTemplate['name']); ?></strong></td>
                    <td><code><?php echo esc_html((string) $jfbTemplate['slug']); ?></code></td>
                    <td>
                        <?php
                        $jfbSources = [
                            TemplateScanner::SOURCE_CHILD_THEME  => __('Child theme', 'jotform-bridge'),
                            TemplateScanner::SOURCE_PARENT_THEME => __('Parent theme', 'jotform-bridge'),
                            TemplateScanner::SOURCE_THEME        => __('Theme', 'jotform-bridge'),
                            TemplateScanner::SOURCE_FILTER       => __('Filter', 'jotform-bridge'),
                        ];

                        echo esc_html($jfbSources[(string) $jfbTemplate['source']] ?? (string) $jfbTemplate['source']);
                        ?>
                    </td>
                    <td>
                        <?php
                        $jfbFields = array_map('strval', (array) $jfbTemplate['fields']);

                        echo $jfbFields === []
                            ? '<span class="description">—</span>'
                            : '<code>' . implode('</code> <code>', array_map('esc_html', $jfbFields)) . '</code>';

                        if ((int) $jfbTemplate['dynamic'] > 0) {
                            echo ' <em>' . esc_html(
                                sprintf(
                                    /* translators: %d: number of dynamic identifiers */
                                    _n(
                                        '+%d dynamic',
                                        '+%d dynamic',
                                        (int) $jfbTemplate['dynamic'],
                                        'jotform-bridge'
                                    ),
                                    (int) $jfbTemplate['dynamic']
                                )
                            ) . '</em>';
                        }
                        ?>
                    </td>
                    <td><code><?php echo esc_html((string) $jfbTemplate['file']); ?></code></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($diagnostics !== []) : ?>
        <h3><?php echo esc_html__('Template diagnostics', 'jotform-bridge'); ?></h3>
        <ul class="ul-disc">
            <?php foreach ($diagnostics as $jfbDiagnostic) : ?>
                <li>
                    <strong><?php echo esc_html(ucfirst((string) $jfbDiagnostic['level'])); ?>:</strong>
                    <?php echo esc_html((string) $jfbDiagnostic['message']); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
