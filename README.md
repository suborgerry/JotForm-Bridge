# Jotform Bridge

A standalone WordPress plugin that uses Jotform as a **headless** form backend.

You write the form markup. Jotform stores the submissions, sends the
notifications and runs its own integrations. Nothing about Jotform reaches your
theme: templates address fields by readable identifiers such as `email` or
`full_name.first`, never by a Jotform question ID, and never by a form ID.

| | |
| --- | --- |
| Requires | WordPress 6.4+, PHP 8.0+ |
| Depends on | nothing — no jQuery, no Composer install, no build step |
| Text domain | `jotform-bridge` |
| Plugin slug | `jotform-bridge` |

---

## Contents

* [How it works](#how-it-works)
* [Installation](#installation)
* [The API key](#the-api-key)
* [API region](#api-region)
* [Integrations](#integrations)
* [Rendering modes](#rendering-modes)
* [Custom templates](#custom-templates)
  * [Header metadata](#header-metadata)
  * [`data-jotform-field`](#data-jotform-field)
  * [Composite fields](#composite-fields)
  * [What a template receives](#what-a-template-receives)
  * [A complete example](#a-complete-example)
  * [Extra template directories](#extra-template-directories)
* [Rendering a form](#rendering-a-form)
* [Success redirect](#success-redirect)
* [Compatibility validation](#compatibility-validation)
* [Sync Schema, Sync with Jotform](#sync-schema-sync-with-jotform)
* [The REST endpoint](#the-rest-endpoint)
* [JavaScript events](#javascript-events)
* [Hooks](#hooks)
* [Spam protection](#spam-protection)
* [Storage and synchronization](#storage-and-synchronization)
* [Supported field types](#supported-field-types)
* [Debugging](#debugging)
* [Uninstalling](#uninstalling)
* [Known limitations](#known-limitations)
* [Development](#development)

---

## How it works

```text
theme:      echo jotform_bridge_render('contact')
                    ↓
integration:  slug "contact" → Jotform form + rendering mode + template
                    ↓
render:     your template, or markup generated from the schema
                    ↓
visitor submits → POST /wp-json/jotform-bridge/v1/submit/contact
                    ↓
server:     validate against the synced schema → map to qids → Jotform API
```

The browser never supplies a form ID, a question ID or credentials. It sends
semantic field names and values; everything else is resolved on the server from
the integration and the synced Jotform form definition.

---

## Installation

1. **Plugins → Add New → Upload Plugin**, upload the ZIP, activate. Nothing else
   is needed: no `composer install`, no `npm install`, no `npm run build`.
2. Add the API key to `wp-config.php` (see below). Until it is there, the admin
   screens say so and nothing can talk to Jotform.
3. **Jotform Bridge → Settings** — choose the API region, save.
4. Press **Test Connection**, then **Sync with Jotform** to load your account's form
   list.
5. **Jotform Bridge → Integrations → Add Integration** — name it, pick the
   Jotform form and the rendering mode.

Get an API key from your Jotform account under **Settings → API**. A read-only
key is enough to load forms and schemas, but creating submissions needs a key
with write access.

---

## The API key

One source, and only one: the `JOTFORM_API_KEY` constant in `wp-config.php`.

```php
// wp-config.php, above the "That's all, stop editing!" comment
define( 'JOTFORM_API_KEY', 'your-api-key' );
```

There is no key field on the settings screen and no key in the database. A
database is the wrong place for this secret: it is dumped into backups, copied
into staging sites and readable by every plugin on the site, and WordPress
offers nothing to encrypt it with that is not stored right next to it. The
constant lives in a file that is not part of a database dump, and a site copied
without its `wp-config.php` simply arrives unconfigured.

When the constant is missing, the settings screen and an admin notice on the
plugin's screens print the line to add and where to add it. A key left in the
option by an earlier version is deleted on upgrade or activation and is never
read.

The key stays on the server. It is never printed into HTML, never localized into
JavaScript, never returned by the REST endpoint and never written to a log; the
admin screen only ever shows the last four characters. If Jotform ever echoes
the key back inside an error message, the client strips it before the message is
stored or displayed.

---

## API region

Jotform serves EU and HIPAA accounts from their own API hosts. Picking the wrong
one does not fail with a clear error — the standard host answers with a redirect
that looks like a permissions problem.

| Region | Base URL |
| --- | --- |
| Standard | `https://api.jotform.com` |
| EU | `https://eu-api.jotform.com` |
| HIPAA | `https://hipaa-api.jotform.com` |
| Custom base URL | your own `http(s)://host[/path]` |

A custom base URL is restricted to an absolute `http` or `https` URL with a
host; credentials, query strings and fragments are stripped before it is stored,
because this setting decides where the API key is sent.

---

## Integrations

An **integration** is the local entity your theme addresses. It binds:

```text
slug  →  Jotform form  →  rendering mode  →  optional template
```

| Field | Meaning |
| --- | --- |
| Name | What you see in the admin |
| Slug | The public identifier used in code, e.g. `contact` |
| Jotform Form | Which form submissions go to |
| Rendering Mode | `Custom template` or `Auto` |
| Template | Which registered template renders it (custom mode) |
| Success Action | `Show the success message` or `Redirect to a page` |
| Redirect Page | Which published page the visitor is sent to (redirect action) |
| Redirect Delay | Seconds to wait before leaving, `0`–`60` |

Several integrations may point at the same Jotform form. That is the point:
`consultation`, `consultation-popup` and `consultation-footer` can share one
Jotform form and use three different templates.

Renaming a slug is a real rename: update the theme code and any shortcode that
referenced the old one.

---

## Rendering modes

**Custom template** — a plain PHP file in your theme. Full control over the
markup. This is the main use case.

**Auto** — markup built from the Jotform form definition: semantic elements,
`jfb-` prefixed classes, labels and options from Jotform, no CSS shipped and no
layout opinions. Use it to get an integration live before a template exists, or
for forms whose presentation does not matter. Fields the plugin cannot map are
left out rather than half-rendered, and the admin says which ones.

---

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
 * Jotform Template Slug: contact
 */
```

| Header | Required | Meaning |
| --- | --- | --- |
| `Jotform Template Name` | yes | Label shown in the admin |
| `Jotform Template Slug` | yes | Identifier the integration stores |
| `Jotform Form ID` | **rejected** | Binding a template to one form is the integration's job |

A file in `jotform-bridge-templates/` with neither header is ignored silently — an ordinary theme
partial in the same directory is not an error. A file with one header and not the
other is reported as an error, because it was clearly meant to be a template.

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

A radio group and a checkbox group each share one `data-jotform-field` across all
their inputs; a checkbox group therefore submits a list. An element without the
attribute takes no part in the payload at all, which is how a honeypot or a
layout helper stays out of it.

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

Three variables, and nothing else:

```php
$integration  // ['slug' => string, 'name' => string, 'template' => string]
$schema       // ['fields' => array<string, field>, 'required' => string[]]
$endpoint     // string: the REST URL this form submits to
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
[`jotform-bridge/examples/contact.php`](jotform-bridge/examples/contact.php).
Copy it to `your-theme/jotform-bridge-templates/contact.php`. The short version:

```php
<?php
/**
 * Jotform Template Name: Contact
 * Jotform Template Slug: contact
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
    <p data-jotform-success role="status" aria-live="polite"></p>
    <div data-jotform-errors role="alert" aria-live="assertive"></div>

    <p>
        <label for="cf-email">Email</label>
        <input type="email" id="cf-email" data-jotform-field="email" required>
        <span data-jotform-field-error="email"></span>
    </p>

    <p>
        <label for="cf-message">Message</label>
        <textarea id="cf-message" data-jotform-field="message"></textarea>
        <span data-jotform-field-error="message"></span>
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

---

## Rendering a form

```php
<?php echo jotform_bridge_render('contact'); ?>
```

```text
[jotform_form id="contact"]
```

Both go through the same code path. `jotform_bridge_render()` never throws and never
prints: an unknown, disabled or misconfigured integration produces an empty
string for visitors, and a short diagnostic for administrators only — a form that
silently vanished is the hardest kind of problem to notice. A template that
raises an error is caught, its half-rendered output discarded, and the page
survives.

The frontend script is registered on every front-end request but **enqueued only
when a form is actually rendered**, so pages without a form ship no extra
JavaScript.

---

## Success redirect

Each integration decides for itself what happens after a submission is accepted:
show the success message and stay on the page, or send the visitor to a page of
this site. Two integrations bound to the same Jotform form can redirect to two
different pages, and the setting is identical for custom templates and automatic
rendering — a template knows nothing about it.

On the Integration screen:

| Field | Meaning |
| --- | --- |
| Success Action | `Show the success message` (default) or `Redirect to a page` |
| Redirect Page | Chosen from the site's published pages — a free URL is not accepted |
| Redirect Delay | `0`–`60` seconds, so the success message can be read first |

**Only the page ID is stored.** The URL is resolved when the submission is
answered, which means a changed permalink takes effect immediately and no stale
or forged URL can be served. The endpoint accepts no redirect input of any kind:
anything redirect-shaped in a request body is an unknown field and fails
validation.

If the chosen page is deleted, trashed, unpublished, or resolves off-site, the
submission still succeeds — the answer simply carries no redirect and the form
shows its success message instead. The Integrations list and the Integration
screen both report this as **Redirect target status**, next to Schema status and
Template compatibility, and the reason is written to the debug log when logging
is on.

A success answer with a redirect looks like this:

```json
{
    "success": true,
    "message": "Form submitted successfully.",
    "redirect": { "url": "https://example.com/thanks/", "delay": 0 }
}
```

The `redirect` key is absent whenever no redirect is configured or the target is
not usable, and it never appears on a validation failure or an upstream error.

The frontend script sets the success state, writes the message, dispatches
`jotformbridge:success`, and only then navigates — with
`window.location.assign()`, after the configured delay, keeping the form
disabled the whole time so a second submission is impossible. A theme that wants
its own flow cancels the navigation:

```js
document.addEventListener('jotformbridge:success', function (event) {
    if (event.detail.redirect) {
        event.preventDefault();               // no navigation happens
        showThanksModal(event.detail.redirect.url);
    }
});
```

---

## Compatibility validation

Every integration is checked against the synced schema and the scanned template
registry. The result is reported when you press **Sync Schema**; the editor's
**Schema** table shows the fields it is derived from:

| State | Meaning |
| --- | --- |
| Compatible | Every required field is present |
| Compatible with warnings | Something is worth knowing — an optional field is missing, a template has identifiers the schema does not know, a semantic key collision exists |
| Invalid | A required Jotform field is not in the template |

Validation is static: it reads the identifiers the scanner extracted from the
template source, not a rendered form. Identifiers your PHP builds at runtime
cannot be checked, so they are counted and reported as *dynamic* rather than
guessed at — a wrong guess would either hide a real problem or invent one.

---

## Sync Schema, Sync with Jotform

Every call to Jotform is a button somebody pressed. There is no cron job, no
background refresh and no expiry anywhere in the plugin.

| Action | Where | What it does |
| --- | --- | --- |
| **Sync Schema** | Integrations list (per row) and integration editor | Reloads **one** form's definition, re-normalizes it, stores it and re-checks compatibility |
| **Test Connection** | Settings | One read-only `GET /user` call; records the result |
| **Sync with Jotform** | Settings | Reloads the account form list from Jotform |
| **Remove from list** | Settings, on a form Jotform reports as `DELETED` | Drops that row from the stored list; nothing is sent to Jotform |
| **Send Test Submission** | Integration editor | Sends one real submission built from the stored schema and shows Jotform's answer verbatim |

**Remove from list** is the one action that touches no API. Jotform keeps
returning the forms in its trash, so a form deleted there would otherwise sit on
the settings screen and in the integration editor's select forever. Removing it
is remembered: the next **Sync with Jotform** leaves it out. Restore the form in
Jotform and it reappears on the next refresh, because it is a form the account
can use again.

Templates are not on that list. They are read from the theme whenever the plugin
needs to know what exists — drop a file into `jotform-bridge-templates/`, and it is in the select.
Edit one, and the compatibility check describes the version on disk. There is
nothing to press.

Sync is per integration on purpose: it moves the contract between one template
and one Jotform form, and a site with ten integrations should never have nine of
them change because somebody wanted the tenth updated.

After changing a form in Jotform — adding a field, making one required,
renaming an option — press **Sync Schema** on the integrations that use it. It
reports whether the form actually changed since the last sync, and re-runs the
compatibility check against your template.

A newly created integration has no schema at all until you sync it: it does not
render, and submissions to it are refused. The editor says so, and the
integrations list has a **Schema** column showing when each form was last
synced.

---

## The REST endpoint

```text
POST /wp-json/jotform-bridge/v1/submit/{integration}
Content-Type: application/json

{ "fields": { "email": "jane@example.com", "topics_of": ["Pricing"] } }
```

Answers:

| Status | Body |
| --- | --- |
| `200` | `{"success": true, "message": "…"}`, plus `"redirect": {"url": "…", "delay": 0}` when the integration redirects |
| `422` | `{"success": false, "message": "Validation failed.", "errors": {"<field>": "…"}}` |
| `403` | The spam check rejected the submission |
| `404` | No such integration |
| `413` | The request body is larger than 256 KB |
| `429` | The identical submission was accepted moments ago |
| `502` / `503` | Jotform rejected the submission, or its definition could not be loaded |

The endpoint is open, because it has to serve anonymous visitors: a nonce would
not protect it and would break page caching. The protection is that the server
trusts nothing in the request except the values themselves —

* the slug is a lookup key; the visitor cannot influence which Jotform form is
  used;
* every field is checked against the synced schema: unknown identifiers are
  rejected, a list where a scalar belongs is rejected, options must be ones
  Jotform reports, emails must be valid, lengths are bounded;
* the payload as a whole is bounded (fields, values, bytes, nesting);
* required means required, whatever the markup claimed;
* the mapping to Jotform parameters is driven by the schema, not by the request;
* the redirect target, if any, comes from the integration and is resolved
  server-side — the request cannot supply, change or suppress one.

Failures never carry upstream detail. What Jotform said is logged (when debug
logging is on) and the visitor gets a generic message.

`jotform_bridge_endpoint('contact')` returns the URL if you need it in PHP; the
template context already provides it as `$endpoint`.

---

## JavaScript events

All three bubble from the `<form>` element and carry a `detail` object.

| Event | `detail` |
| --- | --- |
| `jotformbridge:before-submit` | `{ integration, fields }` — cancelable with `preventDefault()` |
| `jotformbridge:success` | `{ integration, fields, message, redirect }` — `preventDefault()` cancels the redirect |
| `jotformbridge:error` | `{ integration, message, errors, status }` — `preventDefault()` keeps the plugin from moving focus |

`redirect` is `null` unless the integration is configured to redirect and its
target resolved; otherwise it is `{ url, delay }`, with `delay` in seconds.

`fields` on the success event carries the values that were sent, and the form is
still filled in when it fires — the reset happens afterwards. That is what an
analytics or CRM handler needs, and reading it back from the DOM would not work
if the order were the other way round.

After an error the plugin moves focus to the first rejected field, or to the
form-level error container when there is no field to blame; after an accepted
submission that does not redirect, it moves focus to the success message. A
theme that manages focus itself calls `preventDefault()` on the error event.

```js
document.addEventListener('jotformbridge:success', function (event) {
    // Take over the flow: nothing navigates after this.
    event.preventDefault();
    showThanksModal(event.detail.message);
});
```

The plugin imposes no popup and no animation: it toggles state attributes,
writes messages into the slots your template provides, dispatches these events,
and performs the redirect the integration asks for — which the event above can
always cancel. See [Success redirect](#success-redirect).

---

## Hooks

| Hook | Type | When |
| --- | --- | --- |
| `jotform_bridge_template_paths` | filter | Directories scanned for templates |
| `jotform_bridge_normalized_schema` | filter | A schema just before it is stored |
| `jotform_bridge_submission_fields` | filter | Sanitized values before mapping |
| `jotform_bridge_spam_check` | filter | Immediately before the upstream call |
| `jotform_bridge_duplicate_window` | filter | Seconds an identical submission is refused; `0` disables |
| `jotform_bridge_auto_field_html` | filter | Markup of one automatically rendered field |
| `jotform_bridge_before_submit` | action | A validated submission is about to be sent |
| `jotform_bridge_after_submit` | action | Jotform accepted a submission |

---

## Spam protection

No provider ships with the plugin, but the extension point is fixed, so adding
one never means changing the REST controller:

```php
add_filter('jotform_bridge_spam_check', function ($allowed, $slug, $values, $context) {
    if ($slug !== 'contact') {
        return $allowed;
    }

    $token = $context['spam']['turnstile'] ?? '';

    return my_turnstile_verify($token, $context['ip'])
        ? true
        : __('Please confirm you are not a robot.', 'my-theme');
}, 10, 4);
```

Return `true` to allow, `false` to reject with the default message, or a string
to reject with your own. The submission stops there — nothing reaches Jotform.

A challenge token belongs in the request body under `spam`, not in `fields`,
where the validator would quite correctly reject it as an unknown field.

Independently of that, an identical submission from the same visitor is refused
for 30 seconds after one was accepted, so a double click or a retried request
cannot create two Jotform submissions. Only accepted submissions are remembered —
after a failure you can retry immediately — and only a hash is stored, never the
values.

---

## Storage and synchronization

| Data | Where | Expires | Written by |
| --- | --- | --- | --- |
| Normalized schema (one per form) | option `jotform_bridge_schema_{id}` | never | **Sync Schema** |
| Account form list | option `jotform_bridge_forms` | never | **Sync with Jotform** |
| Trashed forms dismissed by hand | option `jotform_bridge_forms_hidden` | never | **Remove from list** |

The template list is deliberately absent: it is not stored at all. Only the
header of each file in `jotform-bridge-templates/` is read, on demand and once per request, which
is cheap enough not to need a cache — and a cache is exactly what used to let an
edited template keep reporting the fields it declared yesterday.

Nothing in this table refreshes itself, and nothing in it expires. A front-end
request — rendering a form or accepting a submission — reads what is stored and
contacts Jotform for exactly one thing: sending an accepted submission. It never
fetches a schema, not even when none is stored; a page with no form on it does
not touch Jotform, the filesystem or the registry at all.

That is a deliberate trade. The site owner decides when a form definition
changes, and an unreachable or slow Jotform API can never appear inside a page
view a visitor is waiting on. The cost is that a form edited in Jotform keeps
rendering the old definition until somebody presses **Sync Schema**.

If a sync fails, the previously stored schema is kept and the error is shown on
the integration screen: a Jotform outage does not take your forms down.

Deactivating the plugin changes nothing at all: there is no derived state left
to drop, so reactivating leaves every form exactly as it was. A plugin upgrade
keeps the schemas too, and marks them as *synced by an older plugin version* so
you can re-sync at a moment you choose. Uninstalling removes both.

---

## Supported field types

Rendered, validated and mapped:

| Jotform field | Semantic type |
| --- | --- |
| Short text | `text` |
| Long text / paragraph | `textarea` |
| Email | `email` |
| Phone | `phone` |
| Number / spinner | `number` |
| Dropdown | `select` |
| Single choice (radio) | `radio` |
| Multiple choice (checkbox) | `checkbox` (list) |
| Full Name | composite → `first`, `last`, `prefix`, `middle`, `suffix` |
| Address | composite → `addr_line1`, `addr_line2`, `city`, `state`, `postal`, `country` |

Not supported: file upload, signature, payment fields, date and time pickers,
star/scale ratings, matrix, appointment, product lists, and Jotform widgets in
general. They are listed in the integration's diagnostics and left out of the
form. A field whose value would be dropped on the way to Jotform is never shown
to a visitor.

Presentation-only Jotform elements — headings, page breaks, dividers, the submit
button — are not fields and are simply not part of the schema.

---

## Debugging

Turn on **Debug Logging** on the settings screen. Failures then go to the PHP
error log with a `[jotform-bridge]` prefix:

```text
[jotform-bridge][ERROR] Jotform returned a non-2xx status. {"path":"/user/forms","status":401}
```

Only technical metadata is logged: a path, a status, an error code, an
integration slug. Never the API key, never an authorization header, never a
submission payload. Logging is off by default.

Common situations:

| Symptom | Cause |
| --- | --- |
| Nothing renders, and you are logged in as an administrator but see no notice | The integration renders fine — check the browser console instead |
| "There is no integration with the slug …" | Typo in the slug, or the integration was renamed |
| "Schema not synced" | Press Sync Schema on that integration; check the API key and the region |
| Every API call fails on an EU account | The region is still set to Standard |
| No template declares this slug | Check the two header lines in the file, and that it sits directly in `jotform-bridge-templates/` |
| Submission answers 503 | The form was never synced, or the synced schema has errors — open the integration editor to see which |
| A field is missing from Auto rendering | Its Jotform type is not supported; the Schema table marks it |

---

## Uninstalling

Deactivating drops the template registry and keeps everything else, synced
schemas included.

Deleting the plugin removes the synced schemas, the form list and the registry
too — but **not** the integrations or the
settings, so the usual "deactivate, delete, reinstall" round trip does not
destroy work somebody did by hand. For a full removal, tick *Delete the
integrations and the settings when the plugin is deleted* on the settings screen
before deleting. The API key is not involved either way: it lives in
`wp-config.php`, which is yours to edit.

---

## Known limitations

* **Field coverage.** Only the field types listed above. File upload, signature,
  payment and date/time fields are not mapped.
* **No conditional logic.** Jotform's show/hide conditions and calculations are
  not evaluated. A template renders every supported field; conditional behaviour
  is up to your own JavaScript.
* **No multi-page forms.** A Jotform form with page breaks is rendered as one
  form.
* **No file uploads.** The submission is `application/x-www-form-urlencoded`; a
  multipart upload path does not exist.
* **No prefill or edit.** Existing submissions are not read back, and there is no
  way to update one.
* **Static template validation only.** Identifiers your PHP builds at runtime are
  counted, not verified.
* **Semantic key collisions.** Two Jotform fields whose machine names normalize
  to the same identifier are reported as an error and the schema is refused
  rather than guessed at. Rename one of the fields in Jotform.
* **Synchronization is manual, by design.** Nothing expires and nothing is
  fetched in the background: a form definition changes here only when you press
  **Sync Schema** on that integration. A form edited in Jotform and not synced
  keeps rendering — and accepting — the previous definition.
* **Duplicate protection is best-effort.** It is a short window on identical
  values from the same IP, not idempotency keys, and two genuinely simultaneous
  requests can still both go through.
* **One site, one Jotform account.** There is no per-integration API key.
* **A redirect target is a page of this site.** It is picked from the published
  pages, not typed as a URL, and an off-site target is refused by design. To send
  a visitor elsewhere, cancel the redirect on `jotformbridge:success` and
  navigate yourself.

---

## Development

The repository is the plugin plus its dev harness. Only `jotform-bridge/` ships.

```text
.
├── jotform-bridge/          # ← the plugin; this directory is the release
│   ├── jotform-bridge.php   # main file with the plugin header
│   ├── uninstall.php
│   ├── readme.txt           # WordPress.org style readme
│   ├── assets/frontend.js
│   ├── examples/contact.php # a complete custom template
│   ├── languages/           # jotform-bridge.pot
│   └── src/
│       ├── Admin/           # settings and integrations screens
│       ├── Api/             # JotformClient, the only HTTP layer
│       ├── Forms/           # normalization, schema, storage
│       ├── Integrations/    # the Integration entity and its storage
│       ├── Rendering/       # both renderers, template context, assets
│       ├── Rest/            # the submission endpoint
│       ├── Settings/
│       ├── Submission/      # validation, mapping, spam guard, pipeline
│       ├── Support/         # logger
│       ├── Templates/       # scanner, registry, validator
│       ├── Autoloader.php   # own PSR-4 loader, so no vendor/ in the release
│       ├── Plugin.php       # composition root
│       └── api.php          # jotform_bridge_render() and friends
├── tests/                   # PHPUnit, WordPress stubbed with Brain Monkey
├── bin/build-zip.sh         # builds the release ZIP
├── AGENTS.md                # architectural specification
└── prompts/                 # the staged prompts this was built from
```

```bash
composer install          # dev dependencies (PHPUnit, Brain Monkey)
vendor/bin/phpunit        # the whole suite; no WordPress needed
bin/build-zip.sh          # dist/jotform-bridge-<version>.zip
```

Regenerating the translation template:

```bash
wp i18n make-pot jotform-bridge jotform-bridge/languages/jotform-bridge.pot --domain=jotform-bridge
```

Architectural invariants worth keeping — they are what the design is:

* A template never knows a Jotform form ID or a question ID.
* The integration is the only binding layer between the two worlds.
* The normalized schema is the single schema contract.
* Custom and Auto rendering share one submission pipeline.
* `JotformClient` is the only code that talks to Jotform.
* The API key is server-only and constant-only; it never reaches the database.
* `TemplateRegistry` is an allowlist; a path is renderable only because it is in
  there.
* Backend validation is authoritative.
