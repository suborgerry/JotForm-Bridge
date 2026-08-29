<?php

/**
 * Integrations list screen.
 *
 * @var array<string, array{integration:\JotformBridge\Integrations\Integration, form_title:string, stats:array<string,int>|null, health:string, last_ok:int}> $rows
 * @var bool                                     $showStats
 * @var \JotformBridge\Templates\TemplateRegistry $templates
 * @var array<int, array<string, string>>         $diagnostics
 * @var array<string, mixed>|null                 $notice
 * @var string                                    $page
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Admin\IntegrationsPage;
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

    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php echo esc_html__('Name', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Jotform Form', 'jotform-bridge'); ?></th>
                <?php if ($showStats) : ?>
                    <th scope="col"><?php echo esc_html__('Last 7 days', 'jotform-bridge'); ?></th>
                <?php endif; ?>
                <th scope="col"><?php echo esc_html__('Shortcode', 'jotform-bridge'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []) : ?>
                <tr>
                    <td colspan="<?php echo $showStats ? 4 : 3; ?>">
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
                        <div class="row-actions visible">
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
                        </div>
                    </td>
                    <td>
                        <?php if ($jfbRow['form_title'] !== '') : ?>
                            <?php echo esc_html($jfbRow['form_title']); ?>
                        <?php else : ?>
                            <span class="description">
                                <?php echo esc_html__('Not in the stored form list', 'jotform-bridge'); ?>
                            </span>
                        <?php endif; ?>
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
                    <?php $jfbShortcode = '[jotform_form id="' . $jfbIntegration->slug() . '"]'; ?>
                    <td>
                        <button
                            type="button"
                            class="button-link jfb-copy-shortcode"
                            data-jfb-shortcode="<?php echo esc_attr($jfbShortcode); ?>"
                            title="<?php echo esc_attr__('Copy to clipboard', 'jotform-bridge'); ?>"
                        >
                            <code><?php echo esc_html($jfbShortcode); ?></code>
                            <span class="jfb-copied"><?php echo esc_html__('Copied', 'jotform-bridge'); ?></span>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php echo esc_html__('Templates', 'jotform-bridge'); ?></h2>

    <p class="description">
        <?php
        $jfbRoots = [];

        foreach ($templates->roots() as $jfbRoot) {
            // The theme name and the directory are the part anybody recognises;
            // the rest of an absolute path only makes the line harder to read.
            $jfbPath    = (string) $jfbRoot['path'];
            $jfbRoots[] = basename(dirname($jfbPath)) . '/' . basename($jfbPath);
        }

        if ($jfbRoots === []) {
            printf(
                /* translators: %s: template directory name */
                esc_html__('No template directory exists yet. Create a %s directory in your theme.', 'jotform-bridge'),
                '<code>' . esc_html(TemplateScanner::DIRECTORY) . '</code>'
            );
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

    <style>
        /* The cell still has to read as the identifier it is, so the button keeps
           the plain <code> look and only gains a pointer and the copied marker. */
        .jfb-integrations .jfb-copy-shortcode {
            position: relative;
            padding: 0;
            border: 0;
            background: none;
            cursor: pointer;
            text-decoration: none;
        }

        .jfb-integrations .jfb-copy-shortcode code {
            cursor: pointer;
        }

        /* Taken out of the flow so that showing it never reflows the row: the
           marker sits to the right of the shortcode and overlaps the cell
           padding instead of widening the column. */
        .jfb-integrations .jfb-copy-shortcode .jfb-copied {
            position: absolute;
            top: 0;
            left: 100%;
            margin-left: .5em;
            color: #007017;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity .15s ease-in-out;
        }

        .jfb-integrations .jfb-copy-shortcode.is-copied .jfb-copied {
            opacity: 1;
        }
    </style>

    <script>
        /* Click-to-copy for the shortcode column. With JavaScript off, or on a
           context where the clipboard is not available, the cell is still the
           shortcode in plain text and can be selected by hand. */
        (function () {
            var buttons = document.querySelectorAll('.jfb-copy-shortcode');
            var timers = [];

            function fallbackCopy(text) {
                var field = document.createElement('textarea');

                field.value = text;
                field.setAttribute('readonly', 'readonly');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();

                var done = false;

                try {
                    done = document.execCommand('copy');
                } catch (error) {
                    done = false;
                }

                document.body.removeChild(field);

                return done;
            }

            function confirmCopy(button, index) {
                button.classList.add('is-copied');
                window.clearTimeout(timers[index]);
                timers[index] = window.setTimeout(function () {
                    button.classList.remove('is-copied');
                }, 1500);
            }

            for (var i = 0; i < buttons.length; i++) {
                (function (button, index) {
                    button.addEventListener('click', function () {
                        var text = button.getAttribute('data-jfb-shortcode') || '';

                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(text).then(
                                function () {
                                    confirmCopy(button, index);
                                },
                                function () {
                                    if (fallbackCopy(text)) {
                                        confirmCopy(button, index);
                                    }
                                }
                            );

                            return;
                        }

                        if (fallbackCopy(text)) {
                            confirmCopy(button, index);
                        }
                    });
                })(buttons[i], i);
            }
        })();
    </script>
</div>
