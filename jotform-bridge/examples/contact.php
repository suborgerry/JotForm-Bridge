<?php
/**
 * Jotform Template Name: Contact
 *
 * A complete custom form template.
 *
 * Copy this file into your theme, in `jotform-bridge-templates/contact.php`,
 * then open Jotform Bridge → Integrations and pick "Contact" as the template
 * of your integration.
 *
 * The header line above is what makes this file a template, and it only sets
 * the name you see in the admin. The identifier an integration stores is the
 * file name, so renaming the file means picking the template again.
 * A `Jotform Form ID` header is deliberately rejected by the scanner: binding a
 * template to one specific Jotform form is the integration's job, which is what
 * lets the same template serve several forms.
 *
 * ---------------------------------------------------------------------------
 * The contract
 * ---------------------------------------------------------------------------
 *
 * Available variables:
 *
 *   $integration  ['slug' => string, 'name' => string, 'template' => string]
 *   $schema       ['fields' => array<string, field>, 'required' => string[]]
 *   $endpoint      string  REST URL this form submits to
 *
 * Each `$schema['fields']` entry is:
 *
 *   key       string   the semantic identifier, e.g. 'email', 'full_name.first'
 *   label     string   the label Jotform reports
 *   type      string   text|textarea|email|phone|number|select|radio|checkbox
 *   required  bool
 *   multiple  bool     true when the field carries a list of values
 *   options   array    [['value' => string, 'label' => string], …]
 *   parent    string   the composite parent key, '' for a scalar field
 *
 * Markup contract:
 *
 *   form[data-jotform-bridge]                 marks the form for the script
 *   form[data-jotform-integration="slug"]     which integration to submit to
 *   [data-jotform-field="key"]                an input carrying one field
 *   [data-jotform-field-error="key"]          where that field's error goes
 *   [data-jotform-errors]                     where form-level errors go
 *   [data-jotform-success]                    where the success message goes
 *   [data-jotform-spam="key"]                 an anti-abuse value, not a field
 *
 * A checkbox group shares one `data-jotform-field` across its inputs; so does a
 * radio group. Everything carrying neither attribute is ignored, which is how a
 * layout helper stays out of the payload.
 *
 * The two attributes are separate on purpose: `data-jotform-field` values are
 * checked against the Jotform schema and an unknown one fails the submission,
 * so a honeypot marked that way would break every send. `$honeypot` and
 * `$turnstile` below are ready-made markup that uses the right one.
 *
 * Nothing here is required to be dynamic: hard-coding the labels and the options
 * is perfectly fine. Reading them from `$schema` only means the form follows the
 * Jotform form when it changes. What you must not do is invent identifiers: a
 * `data-jotform-field` the schema does not know is rejected by the server, and
 * the admin compatibility report tells you about it before a visitor does.
 *
 * The literal strings below are plain text on purpose. This is theme code once
 * you copy it, so wrap them in your own theme's text domain — not in the
 * plugin's.
 *
 * @package JotformBridge
 *
 * @var array<string, string>              $integration
 * @var array<string, mixed>               $schema
 * @var string                             $endpoint
 */

$fields   = $schema['fields'];
$hasField = static fn(string $key): bool => isset($fields[$key]);

/** Labels come from Jotform, so the theme does not have to repeat them. */
$label = static function (string $key, string $fallback = '') use ($fields): string {
    return isset($fields[$key]) && $fields[$key]['label'] !== ''
        ? (string) $fields[$key]['label']
        : $fallback;
};

$required = static function (string $key) use ($fields): bool {
    return isset($fields[$key]) && $fields[$key]['required'];
};

?>
<form
    class="contact-form"
    method="post"
    action="<?php echo esc_url($endpoint); ?>"
    data-jotform-bridge
    data-jotform-integration="<?php echo esc_attr($integration['slug']); ?>"
    novalidate
>
    <p class="contact-form__success" data-jotform-success role="status" aria-live="polite"></p>
    <div class="contact-form__errors" data-jotform-errors role="alert" aria-live="assertive"></div>

    <?php // Escaped by the plugin; print them as they are, anywhere inside the form. ?>
    <?php echo $honeypot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

    <?php if ($hasField('full_name.first')) : ?>
        <div class="contact-form__row">
            <p class="contact-form__field">
                <label for="cf-first">
                    <?php echo esc_html($label('full_name.first', 'First name')); ?>
                </label>
                <input
                    type="text"
                    id="cf-first"
                    autocomplete="given-name"
                    data-jotform-field="full_name.first"
                    aria-describedby="cf-first-error"
                    <?php echo $required('full_name.first') ? 'required' : ''; ?>
                >
                <span class="contact-form__error" id="cf-first-error" data-jotform-field-error="full_name.first"></span>
            </p>

            <p class="contact-form__field">
                <label for="cf-last">
                    <?php echo esc_html($label('full_name.last', 'Last name')); ?>
                </label>
                <input
                    type="text"
                    id="cf-last"
                    autocomplete="family-name"
                    data-jotform-field="full_name.last"
                    aria-describedby="cf-last-error"
                    <?php echo $required('full_name.last') ? 'required' : ''; ?>
                >
                <span class="contact-form__error" id="cf-last-error" data-jotform-field-error="full_name.last"></span>
            </p>
        </div>
    <?php endif; ?>

    <p class="contact-form__field">
        <label for="cf-email">
            <?php echo esc_html($label('email', 'Email')); ?>
        </label>
        <input
            type="email"
            id="cf-email"
            autocomplete="email"
            data-jotform-field="email"
            aria-describedby="cf-email-error"
            <?php echo $required('email') ? 'required' : ''; ?>
        >
        <span class="contact-form__error" id="cf-email-error" data-jotform-field-error="email"></span>
    </p>

    <?php if ($hasField('phone_number')) : ?>
        <p class="contact-form__field">
            <label for="cf-phone">
                <?php echo esc_html($label('phone_number', 'Phone')); ?>
            </label>
            <input
                type="tel"
                id="cf-phone"
                autocomplete="tel"
                data-jotform-field="phone_number"
                aria-describedby="cf-phone-error"
            >
            <span class="contact-form__error" id="cf-phone-error" data-jotform-field-error="phone_number"></span>
        </p>
    <?php endif; ?>

    <?php if ($hasField('preferred_contact')) : ?>
        <fieldset class="contact-form__field">
            <legend><?php echo esc_html($label('preferred_contact')); ?></legend>

            <?php foreach ($fields['preferred_contact']['options'] as $index => $option) : ?>
                <label class="contact-form__choice" for="cf-contact-<?php echo (int) $index; ?>">
                    <input
                        type="radio"
                        id="cf-contact-<?php echo (int) $index; ?>"
                        name="cf-preferred-contact"
                        value="<?php echo esc_attr($option['value']); ?>"
                        data-jotform-field="preferred_contact"
                    >
                    <?php echo esc_html($option['label']); ?>
                </label>
            <?php endforeach; ?>

            <span class="contact-form__error" data-jotform-field-error="preferred_contact"></span>
        </fieldset>
    <?php endif; ?>

    <?php if ($hasField('topics_of')) : ?>
        <fieldset class="contact-form__field">
            <legend><?php echo esc_html($label('topics_of')); ?></legend>

            <?php // One semantic identifier shared by the whole group: the values arrive as a list. ?>
            <?php foreach ($fields['topics_of']['options'] as $index => $option) : ?>
                <label class="contact-form__choice" for="cf-topic-<?php echo (int) $index; ?>">
                    <input
                        type="checkbox"
                        id="cf-topic-<?php echo (int) $index; ?>"
                        value="<?php echo esc_attr($option['value']); ?>"
                        data-jotform-field="topics_of"
                    >
                    <?php echo esc_html($option['label']); ?>
                </label>
            <?php endforeach; ?>

            <span class="contact-form__error" data-jotform-field-error="topics_of"></span>
        </fieldset>
    <?php endif; ?>

    <p class="contact-form__field">
        <label for="cf-message">
            <?php echo esc_html($label('message', 'Message')); ?>
        </label>
        <textarea
            id="cf-message"
            rows="6"
            data-jotform-field="message"
            aria-describedby="cf-message-error"
            <?php echo $required('message') ? 'required' : ''; ?>
        ></textarea>
        <span class="contact-form__error" id="cf-message-error" data-jotform-field-error="message"></span>
    </p>

    <?php // Empty unless the site configured a challenge, so it can be printed always. ?>
    <?php echo $turnstile; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

    <p class="contact-form__actions">
        <button type="submit">Send</button>
    </p>
</form>
