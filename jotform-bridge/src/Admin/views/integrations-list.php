<?php

/**
 * Integrations list screen.
 *
 * @var array<string, array{integration:\JotformBridge\Integrations\Integration, form_title:string}> $rows
 * @var \JotformBridge\Templates\TemplateRegistry $templates
 * @var array<string, array<int, \JotformBridge\Integrations\Integration>> $templateUsage
 * @var array<string, mixed>|null                 $notice
 * @var string                                    $page
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Templates\TemplateScanner;

if (!defined('ABSPATH')) {
    exit;
}

$jfbNewUrl = add_query_arg(['page' => $page, 'view' => 'new'], admin_url('admin.php'));
?>
<div class="wrap jfb-integrations">
    <?php // An h1, like every other screen in wp-admin and every other screen
          // of this plugin: it is how a page announces which page it is, and
          // how somebody navigating by heading finds the top of it. ?>
    <h1 class="wp-heading-inline"><?php echo esc_html__('Integrations', 'jotform-bridge'); ?></h1>
    <a href="<?php echo esc_url($jfbNewUrl); ?>" class="page-title-action">
        <?php echo esc_html__('Add New', 'jotform-bridge'); ?>
    </a>
    <hr class="wp-header-end">

    <?php require __DIR__ . '/partials/notice.php'; ?>
    <?php require __DIR__ . '/partials/live-region.php'; ?>

    <div class="jfb-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__('Integrations', 'jotform-bridge'); ?>">
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php echo esc_html__('Name', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Jotform Form', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Rendering', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Shortcode', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Last modified', 'jotform-bridge'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []) : ?>
                <tr>
                    <td colspan="5">
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

                // A template lives in the theme, so it can be renamed or
                // deleted long after the integration was set up. The row says
                // so instead of leaving the integration looking healthy.
                $jfbTemplateMissing = $jfbIntegration->usesCustomTemplate()
                    && !$templates->has($jfbIntegration->templateSlug());
                ?>
                <tr<?php echo $jfbTemplateMissing ? ' class="jfb-row-broken"' : ''; ?>>
                    <td>
                        <strong>
                            <a href="<?php echo esc_url($jfbEditUrl); ?>">
                                <?php echo esc_html($jfbIntegration->name()); ?>
                            </a>
                        </strong>
                    </td>
                    <td>
                        <?php if ($jfbRow['form_title'] !== '') : ?>
                            <?php echo esc_html($jfbRow['form_title']); ?>
                        <?php elseif ($jfbIntegration->formId() !== '') : ?>
                            <?php /* A form ID that was typed but never resolved: show the ID,
                                     which is the part that actually does the work, and say
                                     what is missing rather than naming a list that no longer
                                     exists. */ ?>
                            <code><?php echo esc_html($jfbIntegration->formId()); ?></code>
                            <br>
                            <span class="description">
                                <?php echo esc_html__('Not connected', 'jotform-bridge'); ?>
                            </span>
                        <?php else : ?>
                            <span class="description">
                                <?php echo esc_html__('No form selected', 'jotform-bridge'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$jfbIntegration->usesCustomTemplate()) : ?>
                            <?php echo esc_html__('Auto (rendered from the schema)', 'jotform-bridge'); ?>
                        <?php else : ?>
                            <?php $jfbUsedTemplate = $templates->get($jfbIntegration->templateSlug()); ?>
                            <?php if ($jfbUsedTemplate !== null) : ?>
                                <?php echo esc_html((string) $jfbUsedTemplate['name']); ?>
                                <br>
                                <code><?php echo esc_html($jfbIntegration->templateSlug()); ?></code>
                            <?php elseif ($jfbIntegration->templateSlug() !== '') : ?>
                                <?php // The slug is kept as stored so a template that went missing stays identifiable. ?>
                                <code><?php echo esc_html($jfbIntegration->templateSlug()); ?></code>
                                <br>
                                <span class="description jfb-broken-note">
                                    <?php
                                    printf(
                                        /* translators: %s: expected template file name */
                                        esc_html__('No theme file named %s. This form does not render.', 'jotform-bridge'),
                                        '<code>' . esc_html($jfbIntegration->templateSlug() . '.php') . '</code>'
                                    );
                                    ?>
                                </span>
                            <?php else : ?>
                                <span class="description jfb-broken-note">
                                    <?php echo esc_html__('No template chosen. This form does not render.', 'jotform-bridge'); ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <?php $jfbShortcode = '[jotform_form id="' . $jfbIntegration->slug() . '"]'; ?>
                    <td>
                        <button
                            type="button"
                            class="button-link jfb-copy-shortcode"
                            data-jfb-copy="<?php echo esc_attr($jfbShortcode); ?>"
                            title="<?php echo esc_attr__('Copy to clipboard', 'jotform-bridge'); ?>"
                        >
                            <code><?php echo esc_html($jfbShortcode); ?></code>
                            <?php // Faded in rather than taken out of the flow, so it stays in the
                                  // accessibility tree at zero opacity and would otherwise be part
                                  // of the button's name before anybody pressed it. The copy is
                                  // announced through the live region above instead. ?>
                            <span class="jfb-copied" aria-hidden="true"><?php echo esc_html__('Copied', 'jotform-bridge'); ?></span>
                        </button>
                    </td>
                    <td>
                        <?php
                        // An integration stored before the timestamps existed
                        // has neither, and the creation time is the closest
                        // truth available for one that was never edited.
                        $jfbModified = $jfbIntegration->updatedAt() > 0
                            ? $jfbIntegration->updatedAt()
                            : $jfbIntegration->createdAt();
                        ?>
                        <?php if ($jfbModified > 0) : ?>
                            <?php echo esc_html($this->formatDateTime($jfbModified)); ?>
                        <?php else : ?>
                            <span aria-hidden="true">&mdash;</span>
                            <span class="screen-reader-text">
                                <?php echo esc_html__('Unknown', 'jotform-bridge'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <h2><?php echo esc_html__('Templates', 'jotform-bridge'); ?></h2>

    <div class="jfb-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__('Templates', 'jotform-bridge'); ?>">
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php echo esc_html__('Template', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Slug', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('File', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Used by', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Last modified', 'jotform-bridge'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($templates->isEmpty()) : ?>
                <tr>
                    <td colspan="5">
                        <?php
                        printf(
                            /* translators: %s: template directory name */
                            esc_html__('No templates found. Add a PHP file with a Jotform template header to your theme %s directory.', 'jotform-bridge'),
                            '<code>' . esc_html(TemplateScanner::DIRECTORY) . '</code>'
                        );
                        ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($templates->all() as $jfbTemplate) : ?>
                <tr>
                    <td><strong><?php echo esc_html((string) $jfbTemplate['name']); ?></strong></td>
                    <td><code><?php echo esc_html((string) $jfbTemplate['slug']); ?></code></td>
                    <td>
                        <?php
                        // Shown the same way as the scanned directories above: the
                        // theme name and the directory place the file, and the rest
                        // of an absolute path only makes the column harder to read.
                        $jfbFile = (string) $jfbTemplate['file'];
                        $jfbDir  = dirname($jfbFile);
                        ?>
                        <code><?php echo esc_html(basename(dirname($jfbDir)) . '/' . basename($jfbDir) . '/' . basename($jfbFile)); ?></code>
                    </td>
                    <td>
                        <?php $jfbUsedBy = $templateUsage[(string) $jfbTemplate['slug']] ?? []; ?>
                        <?php if ($jfbUsedBy === []) : ?>
                            <span aria-hidden="true">&mdash;</span>
                            <span class="screen-reader-text">
                                <?php echo esc_html__('Not used by any integration', 'jotform-bridge'); ?>
                            </span>
                        <?php else : ?>
                            <ul class="jfb-template-usage">
                                <?php foreach ($jfbUsedBy as $jfbUsedByIntegration) : ?>
                                    <?php
                                    $jfbUsedByUrl = add_query_arg(
                                        [
                                            'page'        => $page,
                                            'view'        => 'edit',
                                            'integration' => $jfbUsedByIntegration->slug(),
                                        ],
                                        admin_url('admin.php')
                                    );
                                    ?>
                                    <li>
                                        <a href="<?php echo esc_url($jfbUsedByUrl); ?>">
                                            <?php echo esc_html($jfbUsedByIntegration->name()); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $jfbMtime = (int) ($jfbTemplate['mtime'] ?? 0); ?>
                        <?php if ($jfbMtime > 0) : ?>
                            <?php echo esc_html($this->formatDateTime($jfbMtime)); ?>
                        <?php else : ?>
                            <span aria-hidden="true">&mdash;</span>
                            <span class="screen-reader-text">
                                <?php echo esc_html__('Unknown', 'jotform-bridge'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

</div>
