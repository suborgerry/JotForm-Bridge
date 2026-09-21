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
 * @var array{id:string, title:string, status:string, updated:string, connected_at:int}|null $connectedForm
 * @var array<string, string>                          $templates
 * @var array<string, mixed>|null                      $notice
 * @var string                                         $page
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Forms\FormRepository;
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
    <div class="jfb-page-header">
        <h1 class="wp-heading-inline">
            <?php
            echo $isNew
                ? esc_html__('Add Integration', 'jotform-bridge')
                : esc_html__('Edit Integration', 'jotform-bridge');
            ?>
        </h1>

        <?php
        // Outside the form it submits, so it names the form.
        submit_button(
            $isNew ? __('Create Integration', 'jotform-bridge') : __('Save Integration', 'jotform-bridge'),
            'primary',
            'submit',
            false,
            ['form' => 'jfb-integration-form']
        );
        ?>
    </div>
    <hr class="wp-header-end">

    <?php require __DIR__ . '/partials/notice.php'; ?>
    <?php require __DIR__ . '/partials/live-region.php'; ?>

    <form id="jfb-integration-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-form"><?php echo esc_html__('Jotform Form ID', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <p class="jfb-connect">
                        <input
                            type="text"
                            class="regular-text code"
                            id="jfb-form"
                            name="jotform_integration[form_id]"
                            value="<?php echo esc_attr($integration->formId()); ?>"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            autocomplete="off"
                            aria-describedby="jfb-form-status"
                        >
                        <button
                            type="button"
                            class="button"
                            data-jfb-connect="#jfb-form"
                            data-jfb-connect-target="#jfb-form-status"
                            data-jfb-connect-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                            data-jfb-connect-action="<?php echo esc_attr(IntegrationsPage::ACTION_CONNECT); ?>"
                            data-jfb-connect-nonce="<?php echo esc_attr(wp_create_nonce(IntegrationsPage::ACTION_CONNECT)); ?>"
                            data-jfb-connect-busy="<?php echo esc_attr__('Connecting…', 'jotform-bridge'); ?>"
                        >
                            <?php echo esc_html__('Connect form', 'jotform-bridge'); ?>
                        </button>
                    </p>

                    <?php
                    $jfbConnectState = 'none';
                    if ($connectedForm !== null) {
                        $jfbConnectState = strtoupper($connectedForm['status']) === FormRepository::STATUS_DELETED
                            ? 'warning'
                            : 'ok';
                    }
                    ?>
                    <p
                        id="jfb-form-status"
                        class="jfb-connect-status jfb-state-<?php echo esc_attr($jfbConnectState); ?>"
                        role="status"
                        aria-live="polite"
                    >
                        <?php if ($connectedForm === null && $integration->formId() === '') : ?>
                            <?php echo esc_html__('Not connected yet.', 'jotform-bridge'); ?>
                        <?php elseif ($connectedForm === null) : ?>
                            <?php echo esc_html__('This form ID has never been connected. Press Connect form to check that it exists.', 'jotform-bridge'); ?>
                        <?php else : ?>
                            <?php
                            printf(
                                /* translators: 1: Jotform form title, 2: Jotform form status, e.g. ENABLED */
                                esc_html__('Connected: %1$s (%2$s)', 'jotform-bridge'),
                                esc_html($connectedForm['title'] !== '' ? $connectedForm['title'] : __('untitled form', 'jotform-bridge')),
                                esc_html($connectedForm['status'])
                            );
                            ?>
                            <?php if ($connectedForm['connected_at'] > 0) : ?>
                                <span class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: human readable time difference, e.g. "5 mins" */
                                        esc_html__('Checked %s ago.', 'jotform-bridge'),
                                        esc_html(human_time_diff($connectedForm['connected_at'], time()))
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-mode"><?php echo esc_html__('Rendering Mode', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <select
                        id="jfb-mode"
                        name="jotform_integration[mode]"
                        data-jfb-toggle=".jfb-template-field"
                        data-jfb-toggle-value="<?php echo esc_attr(Integration::MODE_CUSTOM); ?>"
                    >
                        <?php foreach (Integration::modes() as $jfbMode => $jfbLabel) : ?>
                            <option
                                value="<?php echo esc_attr($jfbMode); ?>"
                                <?php selected($integration->mode(), $jfbMode); ?>
                            >
                                <?php echo esc_html($jfbLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr class="jfb-template-field">
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
                                esc_html__('Add a template to your theme %s directory; it is picked up as soon as the file is there.', 'jotform-bridge'),
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
                    <select
                        id="jfb-success-action"
                        name="jotform_integration[success_action]"
                        data-jfb-toggle=".jfb-redirect-field"
                        data-jfb-toggle-value="<?php echo esc_attr(Integration::SUCCESS_REDIRECT); ?>"
                    >
                        <?php foreach (Integration::successActions() as $jfbAction => $jfbActionLabel) : ?>
                            <option
                                value="<?php echo esc_attr($jfbAction); ?>"
                                <?php selected($integration->successAction(), $jfbAction); ?>
                            >
                                <?php echo esc_html($jfbActionLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- redirectPageId() returns int, and the walker escapes it.
                            'selected'          => $integration->redirectPageId(),
                            // wp_dropdown_pages() interpolates show_option_none without escaping it.
                            'show_option_none'  => esc_html__('— Select a page —', 'jotform-bridge'),
                            'option_none_value' => '0',
                            'post_status'       => 'publish',
                        ]
                    );
                    ?>
                    <p class="description">
                        <?php echo esc_html__('Only published pages of this site can be chosen. A free URL is deliberately not accepted.', 'jotform-bridge'); ?>
                    </p>
                    <?php if (RedirectTarget::isBroken($redirect)) : ?>
                        <p class="description jfb-state-error">
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

        <?php require __DIR__ . '/partials/conditional-logic.php'; ?>
    </form>


    <h2><?php echo esc_html__('Schema', 'jotform-bridge'); ?></h2>

    <div class="jfb-actions">
        <?php if (!$isNew) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="jfb-action-form">
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
                class="jfb-action-form"
                data-jfb-confirm="<?php echo esc_attr__('This sends a real submission to Jotform. It will appear in your inbox, trigger the form\'s notifications and count towards your monthly allowance. Continue?', 'jotform-bridge'); ?>"
            >
                <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_TEST); ?>">
                <input type="hidden" name="integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <input type="hidden" name="return_view" value="edit">
                <input type="hidden" name="return_integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <?php wp_nonce_field(IntegrationsPage::ACTION_TEST); ?>
                <?php submit_button(__('Send Test Submission', 'jotform-bridge'), 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($schemaMeta === null || $schemaMeta['synced_at'] === 0) : ?>
        <div class="notice notice-warning inline">
            <p>
                <?php
                // Sync Schema is not rendered on the Add screen; name the button that is.
                echo $isNew
                    ? esc_html__(
                        'This form has never been synced. Nothing is fetched automatically: press Connect form above, which loads the definition along with the title. Until then the form does not render and submissions are refused.',
                        'jotform-bridge'
                    )
                    : esc_html__(
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
                /* translators: %s: human readable time difference */
                esc_html__('Last synced %s ago.', 'jotform-bridge'),
                esc_html(human_time_diff($schemaMeta['synced_at'], time()))
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
        <?php
        $jfbInputs      = count($schema !== null ? $schema->semanticPaths() : []);
        $jfbUnsupported = count($schema !== null ? $schema->unsupportedFields() : []);
        ?>
        <p class="description">
            <?php
            printf(
                /* translators: %d: number of inputs the schema renders */
                esc_html(_n('%d input.', '%d inputs.', $jfbInputs, 'jotform-bridge')),
                (int) $jfbInputs
            );
            ?>
            <?php if ($jfbUnsupported > 0) : ?>
                <?php
                printf(
                    esc_html(
                        /* translators: %d: number of fields left out */
                        _n(
                            '%d Jotform field is not supported and is left out.',
                            '%d Jotform fields are not supported and are left out.',
                            $jfbUnsupported,
                            'jotform-bridge'
                        )
                    ),
                    (int) $jfbUnsupported
                );
                ?>
            <?php endif; ?>
        </p>

        <div class="jfb-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__('Schema', 'jotform-bridge'); ?>">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Label', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Semantic Key', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Type', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Required', 'jotform-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jfbReport->rows() as $jfbRow) : ?>
                    <?php
                    $jfbUnsupported = in_array(
                        (string) $jfbRow['status'],
                        [
                            CompatibilityReport::UNSUPPORTED_REQUIRED,
                            CompatibilityReport::UNSUPPORTED_OPTIONAL,
                        ],
                        true
                    );
                    ?>
                    <tr<?php echo $jfbUnsupported ? ' class="jfb-row-unsupported"' : ''; ?>>
                        <td><?php echo esc_html((string) $jfbRow['label']); ?></td>
                        <td>
                            <?php if ((string) $jfbRow['path'] !== '') : ?>
                                <code><?php echo esc_html((string) $jfbRow['path']); ?></code>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html((string) $jfbRow['type']); ?>
                            <?php if ($jfbUnsupported) : ?>
                                <p class="description jfb-unsupported-note">
                                    <?php
                                    echo esc_html__(
                                        'This Jotform type cannot be mapped yet.',
                                        'jotform-bridge'
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            echo $jfbRow['required']
                                ? esc_html__('Yes', 'jotform-bridge')
                                : esc_html__('No', 'jotform-bridge');
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($scaffold !== '') : ?>
        <?php
        // The scaffold is built from the supported fields alone.
        $jfbScaffoldSkipped = count($schema !== null ? $schema->unsupportedFields() : []);
        ?>
        <h2><?php echo esc_html__('Starter template', 'jotform-bridge'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %s: file name to create in the theme */
                esc_html__(
                    'Built from the schema above. Edit and save it as %s in your theme.',
                    'jotform-bridge'
                ),
                '<code>' . esc_html(TemplateScanner::DIRECTORY . '/' . $scaffoldFile) . '</code>'
            );
            ?>
            <?php if ($jfbScaffoldSkipped > 0) : ?>
                <br>
                <?php
                echo esc_html(
                    _n(
                        'The unsupported field is not part of this starter.',
                        'The unsupported fields are not part of this starter.',
                        $jfbScaffoldSkipped,
                        'jotform-bridge'
                    )
                );
                ?>
            <?php endif; ?>
        </p>

        <p>
            <button
                type="button"
                class="button button-secondary jfb-copy-scaffold"
                data-jfb-copy-from="#jfb-scaffold"
            >
                <?php esc_html_e('Copy to clipboard', 'jotform-bridge'); ?>
                <span class="jfb-copied" aria-hidden="true"><?php esc_html_e('Copied', 'jotform-bridge'); ?></span>
            </button>
        </p>

        <textarea
            id="jfb-scaffold"
            readonly
            rows="20"
            class="large-text code"
            spellcheck="false"
            aria-label="<?php echo esc_attr__('Starter template source', 'jotform-bridge'); ?>"
        ><?php echo esc_textarea($scaffold); ?></textarea>

    <?php endif; ?>

    <?php if (!$isNew) : ?>
        <div class="jfb-actions jfb-actions-footer">
            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                class="jfb-action-form"
                data-jfb-confirm="<?php echo esc_attr__('Delete this integration? This cannot be undone.', 'jotform-bridge'); ?>"
            >
                <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_DELETE); ?>">
                <input type="hidden" name="integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <?php wp_nonce_field(IntegrationsPage::ACTION_DELETE); ?>
                <?php submit_button(__('Delete', 'jotform-bridge'), 'button jfb-button-delete', 'submit', false); ?>
            </form>
        </div>
    <?php endif; ?>
</div>
