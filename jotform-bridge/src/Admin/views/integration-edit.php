<?php

/**
 * Integration editor and its schema/template diagnostics.
 *
 * @var \JotformBridge\Integrations\Integration        $integration
 * @var bool                                           $isNew
 * @var string                                         $originalSlug
 * @var array<string, mixed>                           $compatibility
 * @var array{state:string, url:string, delay:int, label:string, message:string} $redirect
 * @var \JotformBridge\Forms\FormSchema|null           $schema
 * @var array{synced_at:int, fingerprint:string, version:string, error:string}|null $schemaMeta
 * @var bool                                           $schemaStale
 * @var array<int, array<string, string>>              $forms
 * @var array<string, string>                          $templates
 * @var array<string, mixed>|null                      $notice
 * @var string                                         $page
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\RedirectTarget;
use JotformBridge\Templates\CompatibilityReport;
use JotformBridge\Templates\TemplateScanner;

if (!defined('ABSPATH')) {
    exit;
}

$jfbReport  = $compatibility['report'] instanceof CompatibilityReport ? $compatibility['report'] : null;
?>
<div class="wrap jfb-integration">
    <h1 class="wp-heading-inline">
        <?php
        echo $isNew
            ? esc_html__('Add Integration', 'jotform-bridge')
            : esc_html__('Edit Integration', 'jotform-bridge');
        ?>
    </h1>
    <hr class="wp-header-end">

    <?php require __DIR__ . '/partials/notice.php'; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_SAVE); ?>">
        <input type="hidden" name="original_slug" value="<?php echo esc_attr($originalSlug); ?>">
        <?php wp_nonce_field(IntegrationsPage::ACTION_SAVE); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="jfb-name"><?php echo esc_html__('Name', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        class="regular-text"
                        id="jfb-name"
                        name="jotform_integration[name]"
                        value="<?php echo esc_attr($integration->name()); ?>"
                        required
                    >
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-slug"><?php echo esc_html__('Slug', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        class="regular-text code"
                        id="jfb-slug"
                        name="jotform_integration[slug]"
                        value="<?php echo esc_attr($integration->slug()); ?>"
                        pattern="[A-Za-z0-9_\-]+"
                    >
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: example PHP call */
                            esc_html__('The public local identifier, for example %s.', 'jotform-bridge'),
                            '<code>' . esc_html("jotform_bridge_render('contact')") . '</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-form"><?php echo esc_html__('Jotform Form', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <?php if ($forms === []) : ?>
                        <p>
                            <strong><?php echo esc_html__('No forms stored yet.', 'jotform-bridge'); ?></strong>
                            <?php echo esc_html__('Use Sync with Jotform on the Settings screen first.', 'jotform-bridge'); ?>
                        </p>
                    <?php else : ?>
                        <select id="jfb-form" name="jotform_integration[form_id]">
                            <option value=""><?php echo esc_html__('— Select a form —', 'jotform-bridge'); ?></option>
                            <?php foreach ($forms as $jfbForm) : ?>
                                <option
                                    value="<?php echo esc_attr((string) ($jfbForm['id'] ?? '')); ?>"
                                    <?php selected($integration->formId(), (string) ($jfbForm['id'] ?? '')); ?>
                                >
                                    <?php echo esc_html((string) ($jfbForm['title'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Your theme never needs the Jotform form ID — only this integration does.', 'jotform-bridge'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-mode"><?php echo esc_html__('Rendering Mode', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <select id="jfb-mode" name="jotform_integration[mode]">
                        <?php foreach (Integration::modes() as $jfbMode => $jfbLabel) : ?>
                            <option
                                value="<?php echo esc_attr($jfbMode); ?>"
                                <?php selected($integration->mode(), $jfbMode); ?>
                            >
                                <?php echo esc_html($jfbLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php echo esc_html__('A custom template gives the theme full control of the markup. Automatic rendering builds a plain, accessible form from the Jotform schema and ignores the template below.', 'jotform-bridge'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-template"><?php echo esc_html__('Template', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <?php if ($templates === []) : ?>
                        <p>
                            <strong><?php echo esc_html__('No templates registered.', 'jotform-bridge'); ?></strong>
                            <?php
                            printf(
                                /* translators: %s: template directory name */
                                esc_html__('Add a template to your theme %s directory and rescan.', 'jotform-bridge'),
                                '<code>' . esc_html(TemplateScanner::DIRECTORY) . '</code>'
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <select id="jfb-template" name="jotform_integration[template]">
                            <option value=""><?php echo esc_html__('— Select a template —', 'jotform-bridge'); ?></option>
                            <?php foreach ($templates as $jfbSlug => $jfbName) : ?>
                                <option
                                    value="<?php echo esc_attr($jfbSlug); ?>"
                                    <?php selected($integration->templateSlug(), $jfbSlug); ?>
                                >
                                    <?php echo esc_html(sprintf('%s (%s)', $jfbName, $jfbSlug)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-success-action"><?php echo esc_html__('Success Action', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <select id="jfb-success-action" name="jotform_integration[success_action]">
                        <?php foreach (Integration::successActions() as $jfbAction => $jfbActionLabel) : ?>
                            <option
                                value="<?php echo esc_attr($jfbAction); ?>"
                                <?php selected($integration->successAction(), $jfbAction); ?>
                            >
                                <?php echo esc_html($jfbActionLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php echo esc_html__('What happens after a submission is accepted. A redirect target is resolved when the answer is sent, never stored as a URL.', 'jotform-bridge'); ?>
                    </p>
                </td>
            </tr>

            <tr class="jfb-redirect-field">
                <th scope="row">
                    <label for="jfb-redirect-page"><?php echo esc_html__('Redirect Page', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <?php
                    wp_dropdown_pages(
                        [
                            'id'                => 'jfb-redirect-page',
                            'name'              => 'jotform_integration[redirect_page_id]',
                            'selected'          => $integration->redirectPageId(),
                            'show_option_none'  => __('— Select a page —', 'jotform-bridge'),
                            'option_none_value' => '0',
                            'post_status'       => 'publish',
                        ]
                    );
                    ?>
                    <p class="description">
                        <?php echo esc_html__('Only published pages of this site can be chosen. A free URL is deliberately not accepted.', 'jotform-bridge'); ?>
                    </p>
                    <?php if (RedirectTarget::isBroken($redirect)) : ?>
                        <p class="description" style="color:#b32d2e;">
                            <strong><?php echo esc_html((string) $redirect['label']); ?>:</strong>
                            <?php echo esc_html((string) $redirect['message']); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr class="jfb-redirect-field">
                <th scope="row">
                    <label for="jfb-redirect-delay"><?php echo esc_html__('Redirect Delay', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        class="small-text"
                        id="jfb-redirect-delay"
                        name="jotform_integration[redirect_delay]"
                        value="<?php echo esc_attr((string) $integration->redirectDelay()); ?>"
                        min="0"
                        max="<?php echo esc_attr((string) Integration::MAX_REDIRECT_DELAY); ?>"
                        step="1"
                    >
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %d: maximum delay in seconds */
                            esc_html__('Seconds to wait before leaving the page, so the success message can be read. 0 redirects immediately, %d is the maximum.', 'jotform-bridge'),
                            (int) Integration::MAX_REDIRECT_DELAY
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button($isNew ? __('Create Integration', 'jotform-bridge') : __('Save Integration', 'jotform-bridge')); ?>
    </form>

    <script>
        /* The redirect fields are only meaningful for the redirect action. With
           JavaScript off both stay visible, which is a usable form, not a broken
           one: the server ignores them unless the action asks for a redirect. */
        (function () {
            var action = document.getElementById('jfb-success-action');
            var rows = document.querySelectorAll('.jfb-redirect-field');

            if (!action) {
                return;
            }

            function sync() {
                var show = action.value === '<?php echo esc_js(Integration::SUCCESS_REDIRECT); ?>';

                for (var i = 0; i < rows.length; i++) {
                    rows[i].style.display = show ? '' : 'none';
                }
            }

            action.addEventListener('change', sync);
            sync();
        })();
    </script>

    <h2><?php echo esc_html__('Actions', 'jotform-bridge'); ?></h2>
    <div class="jfb-actions">
        <?php if (!$isNew) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_SYNC); ?>">
                <input type="hidden" name="integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <input type="hidden" name="return_view" value="edit">
                <input type="hidden" name="return_integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <?php wp_nonce_field(IntegrationsPage::ACTION_SYNC); ?>
                <?php submit_button(__('Sync Schema', 'jotform-bridge'), 'primary', 'submit', false); ?>
            </form>
        <?php endif; ?>

        <?php if (!$isNew && $canTest && $schema !== null) : ?>
            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                style="display:inline-block;margin-right:8px;"
                onsubmit="return confirm('<?php echo esc_js(__('This sends a real submission to Jotform. It will appear in your inbox, trigger the form\'s notifications and count towards your monthly allowance. Continue?', 'jotform-bridge')); ?>');"
            >
                <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_TEST); ?>">
                <input type="hidden" name="integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <input type="hidden" name="return_view" value="edit">
                <input type="hidden" name="return_integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <?php wp_nonce_field(IntegrationsPage::ACTION_TEST); ?>
                <?php submit_button(__('Send Test Submission', 'jotform-bridge'), 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>

        <?php if (!$isNew) : ?>
            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                style="display:inline-block;"
                onsubmit="return confirm('<?php echo esc_js(__('Delete this integration?', 'jotform-bridge')); ?>');"
            >
                <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_DELETE); ?>">
                <input type="hidden" name="integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <?php wp_nonce_field(IntegrationsPage::ACTION_DELETE); ?>
                <?php submit_button(__('Delete', 'jotform-bridge'), 'delete', 'submit', false); ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($stats !== null && $statsToday !== null) : ?>
        <h2><?php echo esc_html__('Submissions', 'jotform-bridge'); ?></h2>

        <?php
        $jfbLabels = [
            'ok'        => __('Sent to Jotform', 'jotform-bridge'),
            'invalid'   => __('Failed validation', 'jotform-bridge'),
            'empty'     => __('Carried no values', 'jotform-bridge'),
            'duplicate' => __('Sent twice', 'jotform-bridge'),
            'spam'      => __('Refused as spam', 'jotform-bridge'),
            'throttled' => __('Refused by the rate limit', 'jotform-bridge'),
            'quota'     => __('Stopped by the quota guard', 'jotform-bridge'),
            'no_schema' => __('No usable schema', 'jotform-bridge'),
            'upstream'  => __('Refused by Jotform', 'jotform-bridge'),
        ];
        ?>

        <?php if ($statsHealth === 'broken') : ?>
            <p class="notice notice-error inline">
                <strong><?php echo esc_html__('Nothing has got through today.', 'jotform-bridge'); ?></strong>
                <?php
                echo esc_html__(
                    'Submissions are arriving and none of them are reaching Jotform. The table below says why.',
                    'jotform-bridge'
                );
                ?>
            </p>
        <?php elseif ($statsHealth === 'noisy') : ?>
            <p class="notice notice-warning inline">
                <strong><?php echo esc_html__('Almost everything is being refused today.', 'jotform-bridge'); ?></strong>
                <?php
                echo esc_html__(
                    'That is either a lot of bots or a threshold set too tight. If real people are being turned away, relax the limits.',
                    'jotform-bridge'
                );
                ?>
            </p>
        <?php elseif ($statsLastOk > 0) : ?>
            <p>
                <?php
                printf(
                    /* translators: %s: human readable time difference */
                    esc_html__('This form is accepting submissions. The last one arrived %s ago.', 'jotform-bridge'),
                    esc_html(human_time_diff($statsLastOk, time()))
                );
                ?>
            </p>
        <?php endif; ?>

        <?php if ($stats['attempts'] === 0) : ?>
            <p class="description">
                <?php echo esc_html__('Nothing has been submitted in the last seven days.', 'jotform-bridge'); ?>
            </p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:40em;">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__('Outcome', 'jotform-bridge'); ?></th>
                        <th scope="col"><?php echo esc_html__('Today', 'jotform-bridge'); ?></th>
                        <th scope="col"><?php echo esc_html__('7 days', 'jotform-bridge'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jfbLabels as $jfbKey => $jfbLabel) : ?>
                        <?php
                        $jfbWeek = (int) ($stats['counts'][$jfbKey] ?? 0);
                        $jfbDay  = (int) ($statsToday['counts'][$jfbKey] ?? 0);

                        if ($jfbWeek === 0) {
                            continue;
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($jfbLabel); ?></td>
                            <td><?php echo esc_html((string) $jfbDay); ?></td>
                            <td><?php echo esc_html((string) $jfbWeek); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description">
                <?php
                echo esc_html__(
                    'Counts only. No submitted values, no visitor addresses — those live in Jotform and nowhere else.',
                    'jotform-bridge'
                );
                ?>
            </p>
        <?php endif; ?>

        <?php if ($statsFields !== []) : ?>
            <h3><?php echo esc_html__('Fields visitors get wrong most often', 'jotform-bridge'); ?></h3>
            <ul class="ul-disc">
                <?php foreach ($statsFields as $jfbPath => $jfbCount) : ?>
                    <li>
                        <code><?php echo esc_html((string) $jfbPath); ?></code>
                        —
                        <?php
                        printf(
                            /* translators: %d: number of failed submissions */
                            esc_html(
                                _n('%d submission', '%d submissions', (int) $jfbCount, 'jotform-bridge')
                            ),
                            (int) $jfbCount
                        );
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="description">
                <?php
                echo esc_html__(
                    'A field most people trip over is usually a template that does not say what it wants, not a visitor problem.',
                    'jotform-bridge'
                );
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <h2><?php echo esc_html__('Compatibility', 'jotform-bridge'); ?></h2>
    <p>
        <strong><?php echo esc_html((string) $compatibility['label']); ?></strong>
        <?php if ((string) $compatibility['message'] !== '') : ?>
            <?php echo esc_html((string) $compatibility['message']); ?>
        <?php endif; ?>
    </p>

    <?php
    /**
     * Everything the template and the Jotform form disagree about, named the
     * way a developer would go looking for it: the field type, then the exact
     * identifier to put in data-jotform-field.
     */
    $jfbReport   = $compatibility['report'];
    $jfbMismatch = $jfbReport !== null
        ? ['error' => $jfbReport->errors(), 'warning' => $jfbReport->warnings()]
        : ['error' => [], 'warning' => []];
    ?>

    <?php foreach ($jfbMismatch as $jfbLevel => $jfbRows) : ?>
        <?php if ($jfbRows !== []) : ?>
            <h3>
                <?php
                echo $jfbLevel === 'error'
                    ? esc_html__('These stop the form from working', 'jotform-bridge')
                    : esc_html__('Worth a look', 'jotform-bridge');
                ?>
            </h3>
            <ul class="ul-disc">
                <?php foreach ($jfbRows as $jfbRow) : ?>
                    <li>
                        <code>[<?php echo esc_html((string) $jfbRow['type'] !== '' ? (string) $jfbRow['type'] : '—'); ?>]</code>
                        <code>[<?php echo esc_html((string) $jfbRow['path'] !== '' ? (string) $jfbRow['path'] : '—'); ?>]</code>
                        —
                        <?php echo esc_html(CompatibilityReport::label((string) $jfbRow['status'])); ?>
                        <?php if ((string) $jfbRow['message'] !== '') : ?>
                            <span class="description"><?php echo esc_html((string) $jfbRow['message']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>

    <h2><?php echo esc_html__('Redirect target status', 'jotform-bridge'); ?></h2>
    <p <?php echo RedirectTarget::isBroken($redirect) ? 'class="notice notice-warning inline"' : ''; ?>>
        <strong><?php echo esc_html((string) $redirect['label']); ?></strong>
        <?php echo esc_html((string) $redirect['message']); ?>
    </p>

    <h2><?php echo esc_html__('Schema', 'jotform-bridge'); ?></h2>

    <?php if ($schemaMeta === null || $schemaMeta['synced_at'] === 0) : ?>
        <div class="notice notice-warning inline">
            <p>
                <?php
                echo esc_html__(
                    'This form has never been synced. Nothing is fetched automatically: press Sync Schema above to load the definition from Jotform. Until then the form does not render and submissions are refused.',
                    'jotform-bridge'
                );
                ?>
            </p>
        </div>
    <?php else : ?>
        <p class="description">
            <?php
            printf(
                /* translators: 1: human readable time difference, 2: schema fingerprint */
                esc_html__('Last synced %1$s ago. Fingerprint: %2$s', 'jotform-bridge'),
                esc_html(human_time_diff($schemaMeta['synced_at'], time())),
                '<code>' . esc_html(substr($schemaMeta['fingerprint'], 0, 12)) . '</code>'
            );
            ?>
        </p>
        <p class="description">
            <?php
            echo esc_html__(
                'The stored schema never expires and is never refreshed on its own. If the form changed in Jotform, sync it here.',
                'jotform-bridge'
            );
            ?>
        </p>
    <?php endif; ?>

    <?php if ($schemaStale) : ?>
        <div class="notice notice-warning inline">
            <p>
                <?php
                printf(
                    /* translators: 1: plugin version that stored the schema, 2: current plugin version */
                    esc_html__(
                        'This schema was synced by Jotform Bridge %1$s and the site now runs %2$s. It is still used as it is; sync it again when convenient.',
                        'jotform-bridge'
                    ),
                    '<code>' . esc_html($schemaMeta !== null && $schemaMeta['version'] !== '' ? $schemaMeta['version'] : '?') . '</code>',
                    '<code>' . esc_html(JOTFORM_BRIDGE_VERSION) . '</code>'
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($schemaMeta !== null && $schemaMeta['error'] !== '') : ?>
        <div class="notice notice-error inline">
            <p>
                <strong><?php echo esc_html__('Last sync attempt failed:', 'jotform-bridge'); ?></strong>
                <?php echo esc_html($schemaMeta['error']); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($schema !== null && !$schema->isUsable()) : ?>
        <div class="notice notice-error inline">
            <p><?php echo esc_html__('The normalized schema has errors that must be fixed in Jotform:', 'jotform-bridge'); ?></p>
            <ul class="ul-disc">
                <?php foreach ($schema->errors() as $jfbError) : ?>
                    <li><?php echo esc_html((string) $jfbError['message']); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($jfbReport !== null) : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Label', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Semantic Key', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('QID', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Type', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Required', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Template Status', 'jotform-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jfbReport->rows() as $jfbRow) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $jfbRow['label']); ?></td>
                        <td>
                            <?php if ((string) $jfbRow['path'] !== '') : ?>
                                <code><?php echo esc_html((string) $jfbRow['path']); ?></code>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html((string) $jfbRow['qid']); ?></td>
                        <td><?php echo esc_html((string) $jfbRow['type']); ?></td>
                        <td>
                            <?php
                            echo $jfbRow['required']
                                ? esc_html__('Yes', 'jotform-bridge')
                                : esc_html__('No', 'jotform-bridge');
                            ?>
                        </td>
                        <td>
                            <?php echo esc_html(CompatibilityReport::label((string) $jfbRow['status'])); ?>
                            <?php if ((string) $jfbRow['message'] !== '') : ?>
                                <p class="description"><?php echo esc_html((string) $jfbRow['message']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($scaffold !== '') : ?>
        <h2><?php echo esc_html__('Starter template', 'jotform-bridge'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %s: file name to create in the theme */
                esc_html__(
                    'Built from the schema above. Save it as %s in your theme, then pick it as this integration\'s template — it appears in the select straight away. Restyle it however you like: only the data-jotform-* attributes matter.',
                    'jotform-bridge'
                ),
                '<code>' . esc_html(TemplateScanner::DIRECTORY . '/' . $scaffoldFile) . '</code>'
            );
            ?>
        </p>

        <p>
            <button type="button" class="button button-secondary" id="jfb-copy-scaffold">
                <?php esc_html_e('Copy to clipboard', 'jotform-bridge'); ?>
            </button>
            <span id="jfb-copy-scaffold-done" class="description" style="display:none;">
                <?php esc_html_e('Copied.', 'jotform-bridge'); ?>
            </span>
        </p>

        <textarea
            id="jfb-scaffold"
            readonly
            rows="20"
            class="large-text code"
            spellcheck="false"
            aria-label="<?php echo esc_attr__('Starter template source', 'jotform-bridge'); ?>"
        ><?php echo esc_textarea($scaffold); ?></textarea>

        <script>
            (function () {
                var button = document.getElementById('jfb-copy-scaffold');
                var source = document.getElementById('jfb-scaffold');
                var done = document.getElementById('jfb-copy-scaffold-done');

                if (!button || !source) {
                    return;
                }

                button.addEventListener('click', function () {
                    source.focus();
                    source.select();

                    // Selecting is the fallback: where the clipboard API is
                    // unavailable the text is at least ready to copy by hand.
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(source.value);
                    } else {
                        try {
                            document.execCommand('copy');
                        } catch (error) {
                            return;
                        }
                    }

                    if (done) {
                        done.style.display = 'inline';
                    }
                });
            })();
        </script>
    <?php endif; ?>
</div>
