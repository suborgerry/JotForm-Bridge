<?php

/**
 * Integrations list screen.
 *
 * @var array<string, array{integration:\JotformBridge\Integrations\Integration, form_title:string, stats:array<string,int>|null, health:string, last_ok:int}> $rows
 * @var bool                                     $showStats
 * @var \JotformBridge\Templates\TemplateRegistry $templates
 * @var array<string, array<int, \JotformBridge\Integrations\Integration>> $templateUsage
 * @var array<int, array<string, string>>         $diagnostics
 * @var array<string, mixed>|null                 $notice
 * @var string                                    $page
 *
 * @package JotformBridge
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$jfbNewUrl = add_query_arg(['page' => $page, 'view' => 'new'], admin_url('admin.php'));

if (!function_exists('jfb_format_datetime')) {
    /**
     * Both tables print a timestamp the same way: the site's own date and time
     * format, in the site's timezone, so the column matches the rest of the
     * admin instead of inventing a format of its own.
     */
    function jfb_format_datetime(int $timestamp): string
    {
        $date = (string) get_option('date_format', 'Y-m-d');
        $time = (string) get_option('time_format', 'H:i');

        return (string) wp_date(trim($date . ' ' . $time), $timestamp);
    }
}
?>
<div class="wrap jfb-integrations">
    <h2 class="wp-heading-inline"><?php echo esc_html__('Integrations', 'jotform-bridge'); ?></h2>
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
                <th scope="col"><?php echo esc_html__('Rendering', 'jotform-bridge'); ?></th>
                <?php if ($showStats) : ?>
                    <th scope="col"><?php echo esc_html__('Last 7 days', 'jotform-bridge'); ?></th>
                <?php endif; ?>
                <th scope="col"><?php echo esc_html__('Shortcode', 'jotform-bridge'); ?></th>
                <th scope="col"><?php echo esc_html__('Last modified', 'jotform-bridge'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []) : ?>
                <tr>
                    <td colspan="<?php echo $showStats ? 6 : 5; ?>">
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
                                <span class="description" style="color:#b32d2e;">
                                    <?php echo esc_html__('Not in the registry', 'jotform-bridge'); ?>
                                </span>
                            <?php else : ?>
                                <span class="description">
                                    <?php echo esc_html__('No template chosen', 'jotform-bridge'); ?>
                                </span>
                            <?php endif; ?>
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
                            <?php echo esc_html(jfb_format_datetime($jfbModified)); ?>
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

    <h2><?php echo esc_html__('Templates', 'jotform-bridge'); ?></h2>

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
                            <?php echo esc_html(jfb_format_datetime($jfbMtime)); ?>
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
        /* As the first child of .wrap the title is caught by the pre-4.4
           back-compat rule that styles it like a page h1, and
           .wp-heading-inline is only defined for h1, so both are undone here:
           the values below are the ones the "Templates" h2 below gets, plus the
           inline flow and the gap that keep "Add New" beside the title. */
        .jfb-integrations > .wp-heading-inline {
            display: inline-block;
            color: #1d2327;
            font-size: 1.3em;
            font-weight: 600;
            line-height: inherit;
            margin: 1em 4px 1em 0;
            padding: 0;
        }

        /* The cell still has to read as the identifier it is, so the button keeps
           the plain <code> look and only gains a pointer and the copied marker. */
        /* Several integrations can share one template, so the cell is a list
           that stays readable at any count instead of a run-on line. */
        .jfb-integrations .jfb-template-usage {
            margin: 0;
        }

        .jfb-integrations .jfb-template-usage li {
            margin: 0 0 .25em;
        }

        .jfb-integrations .jfb-template-usage li:last-child {
            margin-bottom: 0;
        }

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
