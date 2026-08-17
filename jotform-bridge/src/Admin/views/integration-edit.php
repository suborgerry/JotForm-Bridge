<?php

/**
 * Integration editor and its schema/template diagnostics.
 *
 * @var \JotformBridge\Integrations\Integration        $integration
 * @var bool                                           $isNew
 * @var string                                         $originalSlug
 * @var array<string, mixed>                           $compatibility
 * @var \JotformBridge\Forms\FormSchema|null           $schema
 * @var array{fetched_at:int, fingerprint:string, error:string}|null $schemaMeta
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
use JotformBridge\Templates\CompatibilityReport;

if (!defined('ABSPATH')) {
    exit;
}

$jfbListUrl = add_query_arg(['page' => $page], admin_url('admin.php'));
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
    <a href="<?php echo esc_url($jfbListUrl); ?>" class="page-title-action">
        <?php echo esc_html__('Back to list', 'jotform-bridge'); ?>
    </a>
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
                            '<code>' . esc_html("jotform_form('contact')") . '</code>'
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
                            <strong><?php echo esc_html__('No cached forms.', 'jotform-bridge'); ?></strong>
                            <?php echo esc_html__('Use Refresh Forms on the Settings screen first.', 'jotform-bridge'); ?>
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
                            <?php echo esc_html__('Add a template to your theme /forms/ directory and rescan.', 'jotform-bridge'); ?>
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
                <th scope="row"><?php echo esc_html__('Active', 'jotform-bridge'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="jotform_integration[active]"
                            value="1"
                            <?php checked($integration->isActive()); ?>
                        >
                        <?php echo esc_html__('This integration can be rendered on the site', 'jotform-bridge'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button($isNew ? __('Create Integration', 'jotform-bridge') : __('Save Integration', 'jotform-bridge')); ?>
    </form>

    <h2><?php echo esc_html__('Actions', 'jotform-bridge'); ?></h2>
    <div class="jfb-actions">
        <?php if (!$isNew) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_REFRESH); ?>">
                <input type="hidden" name="integration" value="<?php echo esc_attr($integration->slug()); ?>">
                <?php wp_nonce_field(IntegrationsPage::ACTION_REFRESH); ?>
                <?php submit_button(__('Refresh Schema', 'jotform-bridge'), 'secondary', 'submit', false); ?>
            </form>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
            <input type="hidden" name="action" value="<?php echo esc_attr(IntegrationsPage::ACTION_RESCAN); ?>">
            <input type="hidden" name="return_view" value="<?php echo $isNew ? 'new' : 'edit'; ?>">
            <input type="hidden" name="return_integration" value="<?php echo esc_attr($integration->slug()); ?>">
            <?php wp_nonce_field(IntegrationsPage::ACTION_RESCAN); ?>
            <?php submit_button(__('Rescan Templates', 'jotform-bridge'), 'secondary', 'submit', false); ?>
        </form>

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

    <h2><?php echo esc_html__('Compatibility', 'jotform-bridge'); ?></h2>
    <p>
        <strong><?php echo esc_html((string) $compatibility['label']); ?></strong>
        <?php if ((string) $compatibility['message'] !== '') : ?>
            <?php echo esc_html((string) $compatibility['message']); ?>
        <?php endif; ?>
    </p>

    <?php if ($schemaMeta !== null && $schemaMeta['fetched_at'] > 0) : ?>
        <p class="description">
            <?php
            printf(
                /* translators: 1: human readable time difference, 2: schema fingerprint */
                esc_html__('Schema loaded %1$s ago. Fingerprint: %2$s', 'jotform-bridge'),
                esc_html(human_time_diff($schemaMeta['fetched_at'], time())),
                '<code>' . esc_html(substr($schemaMeta['fingerprint'], 0, 12)) . '</code>'
            );
            ?>
        </p>
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
</div>
