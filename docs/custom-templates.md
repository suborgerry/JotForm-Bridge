# Custom templates

[Documentation](README.md)

## Custom templates

Templates live in a `jotform-bridge-templates/` directory inside the theme:

```text
wp-content/themes/your-theme/
└── jotform-bridge-templates/
    ├── contact.php
    └── consultation.php
```

A child theme overrides a parent template with the same slug. Discovery is
shallow — nested directories are an implementation detail of a template, not
templates themselves — and it works by **reading** the file header. A template is
never executed during discovery.

Adding, renaming or removing a template file takes effect immediately: the theme is read whenever the plugin needs the list.

### Header metadata

```php
<?php
/**
 * Jotform Template Name: Contact
 */
```

| Header | Required | Meaning |
| --- | --- | --- |
| `Jotform Template Name` | yes | Label shown in the admin |
| `Jotform Template Slug` | **ignored** | Left over from an earlier version; the file name is the slug |
| `Jotform Form ID` | **rejected** | Binding a template to one form is the integration's job |

The slug an integration stores is the file name run through `sanitize_key()`:
`jotform-bridge-templates/contact.php` is `contact`, and a child theme overrides
a parent template by using the same file name. Renaming the file therefore means
picking the template again in the integration — the list says so, in red, on the
row whose template no longer exists.

A file in `jotform-bridge-templates/` without the name header is ignored silently
— an ordinary theme partial in the same directory is not an error.

### `data-jotform-field`

One attribute carries the whole contract between your markup and the plugin:

```html
<input type="email" data-jotform-field="email">
```

Never a question ID, never a Jotform `name`:

```html
<!-- wrong -->
<input name="q7">
```

The identifier is derived from the Jotform field's machine name — `fullName`
becomes `full_name`, `Phone Number` becomes `phone_number` — so it survives a
label being renamed or translated.

| Attribute | Where | Purpose |
| --- | --- | --- |
| `data-jotform-bridge` | `<form>` | Marks the form for the frontend script |
| `data-jotform-integration` | `<form>` | Which integration to submit to |
| `data-jotform-endpoint` | `<form>` | Optional explicit endpoint URL |
| `data-jotform-field` | input, textarea, select | The field this control carries |
| `data-jotform-field-error` | any element | Where that field's error message goes |
| `data-jotform-errors` | any element | Where form-level errors go |
| `data-jotform-success` | any element | Where the success message goes |
| `data-jotform-busy` | `<form>` | Set to `true` while a submission is in flight |
| `aria-busy` | `<form>` | The same state, spelled the way assistive technology reads it |
| `aria-disabled` | submit button | Set to `true` while a submission is in flight |

A radio group and a checkbox group each share one `data-jotform-field` across all
their inputs; a checkbox group therefore submits a list. An element without the
attribute takes no part in the payload at all, which is how a honeypot or a
layout helper stays out of it.

Give every error slot an `id`, and every control it belongs to an
`aria-describedby` naming it — for a group, all of its inputs name the one slot
the group shares. That association is what makes the server's message part of
the field a screen reader announces when the script moves focus to it. Without
it the message is written into the page and read by nobody: the control is
announced as invalid, and never says why.

While a submission is in flight the submit button is marked `aria-disabled`
rather than `disabled`. A disabled control cannot hold focus, so the browser
would move focus to the document body the moment the visitor pressed Submit and
leave them there until the answer arrived. The repeated submit this prevents is
refused by the script itself, which returns as soon as it sees the busy
attribute. Style the state through `form[data-jotform-busy]` or
`[aria-disabled="true"]`.

An identifier the schema does not know is **rejected**, not dropped: a
template/schema mismatch surfaces instead of quietly losing an answer.

### Composite fields

Jotform's Full Name and Address fields are addressed through their children
only, using a dotted path:

```html
<input data-jotform-field="full_name.first">
<input data-jotform-field="full_name.last">

<input data-jotform-field="address.addr_line1">
<input data-jotform-field="address.city">
<input data-jotform-field="address.postal">
```

The part before the dot is the field's own semantic key, so an address field
named `homeAddress` exposes `home_address.city`.

| Composite | Children |
| --- | --- |
| Full Name | `first`, `last`, plus `prefix`, `middle`, `suffix` when the field shows them |
| Address | whichever of `addr_line1`, `addr_line2`, `city`, `state`, `postal`, `country` the field shows |

The exact set comes from the Jotform field configuration. The admin
compatibility report lists every path a template may use for the bound form —
read it there rather than guessing.

### What a template receives

The template receives safe rendering variables:

```php
$integration  // ['slug' => string, 'name' => string, 'template' => string]
$schema       // ['fields' => array<string, field>, 'required' => string[]]
$endpoint     // string: the REST URL this form submits to
$honeypot     // print inside the form
$turnstile    // print inside the form; empty when not configured
$noscript     // notice for visitors without JavaScript
```

Each `$schema['fields']` entry:

| Key | Type | Meaning |
| --- | --- | --- |
| `key` | string | The semantic identifier |
| `label` | string | The label Jotform reports |
| `type` | string | `text`, `textarea`, `email`, `phone`, `number`, `select`, `radio`, `checkbox` |
| `required` | bool | Whether Jotform marks it required |
| `multiple` | bool | True when the field carries a list |
| `options` | array | `[['value' => string, 'label' => string], …]` |
| `parent` | string | The composite parent key, `''` for a scalar field |

The Jotform form ID, the question IDs and the API key are not part of the context
and cannot be reached from it. A template that wanted to leak them would have
nothing to leak.

Using `$schema` is optional. Hard-coding labels and options is fine; reading them
from the schema only means the form follows the Jotform form when it changes.

### A complete example

A ready-to-copy template ships with the plugin at
[`jotform-bridge/examples/contact.php`](../jotform-bridge/examples/contact.php).
Copy it to `your-theme/jotform-bridge-templates/contact.php`. The short version:

```php
<?php
/**
 * Jotform Template Name: Contact
 */
?>
<form
    class="contact-form"
    method="post"
    action="<?php echo esc_url($endpoint); ?>"
    data-jotform-bridge
    data-jotform-integration="<?php echo esc_attr($integration['slug']); ?>"
    novalidate
>
    <?php echo $honeypot; // Plugin-generated markup. ?>
    <?php echo $turnstile; // Plugin-generated markup. ?>
    <?php echo $noscript; // Plugin-generated markup. ?>
    <p data-jotform-success role="status" aria-live="polite"></p>
    <div data-jotform-errors role="alert" aria-live="assertive"></div>

    <p>
        <label for="cf-email">Email</label>
        <input type="email" id="cf-email" data-jotform-field="email"
               aria-describedby="cf-email-error" required>
        <span id="cf-email-error" data-jotform-field-error="email"></span>
    </p>

    <p>
        <label for="cf-message">Message</label>
        <textarea id="cf-message" data-jotform-field="message"
                  aria-describedby="cf-message-error"></textarea>
        <span id="cf-message-error" data-jotform-field-error="message"></span>
    </p>

    <button type="submit">Send</button>
</form>
```

No CSS ships with the plugin. Style it as you would any other form in the theme.

### Extra template directories

```php
add_filter('jotform_bridge_template_paths', function (array $paths): array {
    $paths[] = get_stylesheet_directory() . '/components/forms';

    return $paths;
});
```

Paths must be absolute. Each one is resolved with `realpath()`, and every
discovered file is verified to actually live inside it — a symlink that escapes
its directory is reported and ignored.
