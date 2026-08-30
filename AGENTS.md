# AGENTS.md — Jotform Bridge

> **Status.** The seven stages in `prompts/` are implemented; those files are
> history rather than assignments, and are not to be carried out again. This
> specification describes what already exists. The sections at the end of the
> document beginning with `Amendment:` **overturn** earlier requirements and
> take precedence over them — reading them is not optional.
>
> **Language.** Everything written down here is in English: this specification,
> the prompts, the code, the comments, the commit messages, `README.md`,
> `TODO.md` and `readme.txt`. Every user-facing string goes through `__()` with
> the `jotform-bridge` text domain and is written in English — translation is
> the job of a `.po` file, not of the source.

## The project

We are building a standalone WordPress plugin:

**Jotform Bridge**

Its purpose is to use Jotform as a headless backend for WordPress forms.

Jotform is responsible for:

* form structure;
* submissions;
* email notifications;
* integrations;
* storing the data.

WordPress is responsible for:

* the frontend HTML;
* custom templates;
* UX;
* sending the data through its own REST endpoint;
* server-side validation;
* mapping the data into Jotform.

The main scenario is completely custom HTML markup for the form.

Generating a form automatically from the Jotform schema is also supported.

---

# Minimum requirements

Supported:

* WordPress >= 6.4
* PHP >= 8.0

The plugin header must contain:

```text
Requires at least: 6.4
Requires PHP: 8.0
```

Do not use PHP features introduced after PHP 8.0 where they would break
compatibility with PHP 8.0.

In particular, do not require:

* enums;
* readonly properties;
* intersection types;
* PHP 8.1+ syntax.

PHP 8.0 features are available:

* typed properties;
* union types;
* constructor property promotion;
* match;
* the nullsafe operator.

---

# Standalone

The plugin must be completely standalone.

It must NOT depend on:

* Sage;
* Blade;
* Acorn;
* ACF;
* Elementor;
* WooCommerce;
* Gutenberg;
* jQuery;
* React;
* Vue;
* Laravel;
* any particular WordPress theme;
* any particular site;
* Node.js in production;
* the site's frontend build system.

Sage and Blade are not supported and not taken into account in this version.

Version 1 custom templates are plain PHP templates.

---

# Composer

Composer is allowed for development and for PSR-4 autoloading.

The final plugin package, however, must work after an ordinary WordPress plugin
installation. The user must not have to run:

```bash
composer install
```

after installing the ZIP.

If Composer autoloading is used, the production package has to contain the
runtime autoload files it needs.

Do not add third-party Composer dependencies without a real need. Prefer the
WordPress Core API.

---

# Repository layout

The plugin code lives in its own directory at the repository root:

```text
jotform-bridge/
```

Everything else at the root is development scaffolding and never ships.

Roughly:

```text
.
├── AGENTS.md
├── CLAUDE.md              # imports AGENTS.md; not a second copy
├── README.md
├── prompts/
├── composer.json          # dev dependencies and PSR-4 autoload
├── phpunit.xml.dist
├── phpcs.xml.dist         # coding standards
├── phpstan.neon.dist      # static analysis
├── bin/                   # dev scripts; phpstan-bootstrap.php lives here
│                          # because no PHP may sit at the repository root
├── tests/
│   ├── Unit/
│   └── Fixtures/
└── jotform-bridge/        # ← this is the plugin
    ├── jotform-bridge.php # main plugin file with the header
    ├── uninstall.php
    ├── src/
    ├── assets/
    │   ├── frontend.js
    │   ├── admin.js
    │   └── admin.css
    ├── languages/
    ├── readme.txt         # WordPress.org style, stage 6
    └── vendor/            # production autoload only, if used at all
```

Rules:

* the main plugin file is named `jotform-bridge.php`;
* plugin slug: `jotform-bridge`;
* text domain: `jotform-bridge`;
* namespace root: `JotformBridge\`;
* PSR-4 mapping: `JotformBridge\` → `jotform-bridge/src/`;
* prefix for options, transients and hooks: `jotform_bridge_`;
* prefix for CSS classes: `jfb-`.

Do not put plugin PHP code at the repository root.

Do not put tests or fixtures inside `jotform-bridge/`.

---

# Building a release

The release ZIP is the contents of the `jotform-bridge/` directory and nothing
else.

The ZIP must not contain:

```text
AGENTS.md
CLAUDE.md
prompts/
tests/
composer.json
phpunit.xml.dist
phpcs.xml.dist
phpstan.neon.dist
bin/
.codex/
.claude/
node_modules/
dev dependencies inside vendor/
```

Unpacked into `wp-content/plugins/`, the plugin has to work without:

```bash
composer install
npm install
npm run build
```

If Composer is used for autoloading, `jotform-bridge/vendor/` must hold a
production autoloader generated with `--no-dev`.

If there are no third-party runtime dependencies, a small PSR-4 autoloader of
our own inside `src/` is preferable, with no `vendor/` directory in the release
at all.

---

# Git workflow

One stage is one coherent set of commits.

* work in a branch such as `stage-1-plugin-core`, `stage-2-schema-engine`;
* do not commit to `main` directly;
* do not commit secrets, dev dependencies in `vendor/`, or local WordPress files;
* commit at logical stages, with a message that says what was implemented and why;
* do not merge, push or open a pull request without being asked to.

---

# The central architectural idea

Do not tie the frontend to a Jotform Form ID or Question ID.

Public code works through a local entity:

**Integration**

For example:

```text
contact
consultation
career
```

An Integration binds together:

```text
local slug
    ↓
Jotform Form
    ↓
rendering mode
    ↓
optional custom template
```

Theme code must never contain a Jotform Form ID.

---

# Integration

An Integration holds at least:

```text
Name
Slug
Jotform Form ID
Rendering Mode
Template Slug
Success Action
Redirect Page ID
Redirect Delay
```

Success actions:

```text
message
redirect
```

`message` is the default: show the success message in the template's slot.

`redirect` sends the visitor to the chosen page after a successful submission.

The redirect target is chosen per Integration.

Rendering modes:

```text
custom
auto
```

One Jotform Form may be used by several integrations. For example:

```text
Jotform Form:
Request Consultation

Integrations:
consultation
consultation-popup
consultation-footer
```
That is a deliberate requirement.

---

# Storing data

Do not create custom database tables without a need.

Use WordPress options. Preferably split as:

```text
jotform_bridge_settings
jotform_bridge_integrations
```

or similarly namespaced options.

All data is:

* sanitized when saved;
* validated before use.

---

# The Jotform API

There is a dedicated:

```text
JotformClient
```

It is the only low-level layer that talks to the Jotform REST API. No other part
of the plugin builds HTTP requests to Jotform of its own.

Use:

```php
wp_remote_get()
wp_remote_post()
```

or the corresponding WordPress HTTP API functions. Do not use cURL directly
without an objective reason.

JotformClient has to handle:

* WP_Error;
* timeouts;
* non-2xx responses;
* malformed responses;
* API-level errors;
* an unavailable service.

Never fail silently.

---

# Jotform API documentation

Do not guess the shape of the Jotform REST API.

Before implementing a particular API interaction, check the current official
Jotform documentation. This matters most for:

* authentication;
* fetching forms;
* fetching questions;
* the submission endpoint;
* the submission payload;
* composite fields;
* checkbox, radio and select formats.

The Jotform MCP server is NOT a substitute for the REST API documentation. The
production plugin uses the Jotform REST API.

---

# The API key

The Jotform API key must never reach the frontend and must never reach the
database.

Its only source is a constant in `wp-config.php`:

```php
define('JOTFORM_API_KEY', '...');
```

A WordPress option is not a key source: the admin has no field for entering one,
`save()` never writes one, and a key left in the option by an earlier version is
deleted on upgrade or activation and never read.

If the constant is not defined:

* no API calls are made;
* an admin notice on the plugin's screens and on the plugin list gives the exact
  line to add to `wp-config.php`;
* the API Key row on the settings screen carries the same instruction.

The API key:

* is never printed back in full — the last four characters at most;
* never appears in frontend HTML;
* never appears in JavaScript;
* is never returned through the REST API;
* is never written to the debug log.

---

# Secrets and the test environment

## Where credentials come from

The agent does **not** store and does **not** ask for an API key in
conversation.

In order:

1. a local `.env` at the repository root (git-ignored, never committed);
2. the `JOTFORM_API_KEY` environment variable;
3. if neither exists — do not invent a key and do not ask for one to be pasted
   into a message. Run the checks against fixtures and mocks instead, and state
   plainly in the report that the live connection was not exercised.

An `.env.example` template, with no values in it, stays in the repository.

Never write a real key into:

* `AGENTS.md`;
* `README.md`;
* `prompts/`;
* tests and fixtures;
* any commit.

## The test Jotform form

```text
Test form ID:            262215084646053  (the "Test API" form)
Permission for live writes: <not granted>
```

The key and the test form ID live in the local `.env` and are not committed.
The account is in the EU region: `api.jotform.com` answers `responseCode 301`
telling us to use `eu-api.jotform.com`, so local checks select the EU region in
the plugin settings.

Live writes are still not permitted. Until permission is granted, the safe mode
applies:

* read-only calls to Jotform are allowed;
* **no** submissions into the real account;
* no changes to forms and no deletions;
* end-to-end checks run up to the upstream boundary with the Jotform API mocked;
* the stage report says explicitly that no live upstream write was performed.

Stages 4, 5 and 6 mention a live submission as a conditional possibility. The
condition counts as unmet until an explicit permission for live writes appears
in this block next to the test form ID.

The production form is not to be used under any circumstances.

---

# Jotform region

Every API URL is built in one place. Do not hardcode the API base URL in several
classes.

The region and base URL are part of the JotformClient configuration, and the
design has to allow Standard, EU and other Jotform environments.

---

# Schema

The Jotform question schema cannot be used directly in the frontend. There is a
layer in between:

```text
Jotform Questions
       ↓
FieldNormalizer
       ↓
Normalized Schema
```

The Normalized Schema is the internal contract of the application.

---

# Semantic fields

A custom template must not know a Jotform qid. This must not be required:

```html
<input name="q7">
```

nor this:

```html
<input data-jotform-field="7">
```

Use semantic identifiers instead:

```html
<input data-jotform-field="email">
```

Composite fields:

```html
<input data-jotform-field="name.first">
<input data-jotform-field="name.last">
```

Address:

```html
<input data-jotform-field="address.addr_line1">
<input data-jotform-field="address.addr_line2">
<input data-jotform-field="address.city">
<input data-jotform-field="address.state">
<input data-jotform-field="address.postal">
<input data-jotform-field="address.country">
```

**Established in stage 2** from the Jotform API rather than invented:

* Full Name — `sublabels` keys `prefix`, `first`, `middle`, `last`, `suffix`;
  `first` and `last` are always present, the rest only when
  `prefix|middle|suffix = Yes`.
* Address — answer/prefill keys `addr_line1`, `addr_line2`, `city`, `state`,
  `postal`, `country`; which of them are present is decided by the `subfields`
  property (tokens `st1|st2|city|state|zip|country`).

The parent part of the path is the semantic key of the field itself (from the
Jotform `name`), so `address` in the examples above is an illustration, not a
fixed name.

Do not bend the normalization to fit the examples in this project's own
documentation.

A template developer must not need to know internal Jotform Question IDs.

---

# Semantic key generation

Use the most stable machine-readable identifier the Jotform schema provides. Do
not rely on the visible label alone: labels change, repeat, contain spaces and
contain special characters.

The raw Jotform qid is kept inside the normalized schema as the authoritative
mapping.

If a semantic key collision cannot be resolved unambiguously:

* do not guess;
* mark the schema as problematic;
* show the administrator a comprehensible error.

---

# FieldNormalizer

FieldNormalizer converts Jotform-specific data into the internal format.
Conceptually:

```php
[
    'key'      => 'email',
    'qid'      => '7',
    'type'     => 'email',
    'required' => true,
]
```

Composite:

```php
[
    'key'      => 'name',
    'qid'      => '3',
    'type'     => 'name',
    'required' => true,
    'children' => [
        'first',
        'last',
    ],
]
```

The main supported types:

* textbox;
* textarea;
* email;
* phone;
* full name;
* address;
* dropdown/select;
* radio;
* checkbox;
* number;
* date, where it is possible without wrong assumptions.

Unsupported fields:

* must not silently corrupt data;
* must be marked as unsupported;
* must be visible to the administrator.

---

# Custom templates

Custom templates live in the active WordPress theme.

The base directory:
```text
/jotform-bridge-templates/
```

For example:

```text
wp-content/themes/example/
└── jotform-bridge-templates/
    ├── contact.php
    └── consultation.php
```

Supported:

* the active theme;
* a child theme;
* a parent theme.

The child theme wins when a template slug appears in both.

A filter is provided:

```text
jotform_bridge_template_paths
```

so developers can add further template directories.

---

# Template metadata

A template is discovered by its file header:

```php
<?php
/*
Jotform Template Name: Contact Form
*/
?>
```

The required metadata:

```text
Jotform Template Name
```

The template slug is the file name passed through `sanitize_key()`
(`jotform-bridge-templates/contact.php` → `contact`). The
`Jotform Template Slug` header survives only for older templates: it is ignored,
and the scanner reports a warning about it.

A Jotform Form ID inside a template is forbidden. This is wrong:

```text
Jotform Form ID: 123456789
```

A template identifies a frontend template and nothing else. The binding:

```text
Template
↔
Jotform Form
```

lives in the Integration configuration.

---

# TemplateScanner

TemplateScanner must:

* scan permitted directories only;
* find PHP templates;
* read the header without executing the file;
* check the metadata;
* build the registry;
* reject duplicate slugs with a comprehensible diagnostic;
* honour child theme priority;
* prevent directory traversal.

Discovery reads only the header of each file (the first 8 KB) and runs on
demand, with no cache and no Rescan button. For the reasoning see
"Amendment: template discovery reads the theme on demand".

Analysing a template's fields (`fields()`) reads the whole file and is called
only by the compatibility report in the admin, never on a frontend path.

---

# TemplateRegistry

A registry entry, conceptually:

```php
[
    'slug' => 'contact',
    'name' => 'Contact Form',
    'file' => '/trusted/path/jotform-bridge-templates/contact.php',
]
```

Only a file discovered by TemplateScanner, inside a permitted template path, may
be rendered.

Never render an arbitrary path that came from:

* $_GET;
* $_POST;
* a REST request;
* an admin field.

---

# Template validation

TemplateValidator compares the:

```text
data-jotform-field
```

identifiers in a custom template against the Normalized Schema of the Jotform
form.

Static semantic identifiers in templates have to be literal strings:

```html
data-jotform-field="email"
```

TemplateValidator has to report:

* required fields present;
* required fields missing;
* optional fields missing;
* unknown template fields;
* unsupported schema fields.

Statuses:

```text
Compatible
Compatible with warnings
Invalid
```

Example diagnostics:

```text
Email       email        ✓
First Name  name.first   ✓
Last Name   name.last    ✓
Company     company      Missing optional
Phone       phone        Missing required
```

A missing required field is an:

```text
ERROR
```

A missing optional field is a:

```text
WARNING
```

---

# Schema refresh

A Jotform form can change outside WordPress, so the schema is not to be treated
as permanent.

Store the normalized schema and its fingerprint/hash in an option with no TTL —
see "Amendment: manual schema synchronization".

There is a:

```text
Sync Schema
```

action. After a sync:

1. fetch the current Jotform schema;
2. normalize it;
3. overwrite the stored schema;
4. recompute template compatibility.

Do not call the Jotform API on every frontend page view.

---

# Rendering

There are two renderers:

```text
CustomTemplateRenderer
AutoRenderer
```

The custom template is the main scenario. AutoRenderer was built after the
custom-template flow already worked.

A renderer uses:

```text
Integration
+
Normalized Schema
```

Do not call the Jotform API during ordinary rendering. If the schema has not
been synced, the form does not render rather than fetching it.

---

# The PHP rendering API

A public helper is provided:

```php
jotform_bridge_render('contact')
```

It returns HTML:

```php
echo jotform_bridge_render('contact');
```

There is also a shortcode:

```text
[jotform_form id="contact"]
```

The shortcode and the PHP helper use the same rendering service. Business logic
is not duplicated between them.

---

# The custom template context

A custom PHP template receives the safe context it needs in order to render, for
example:

```text
integration
schema
endpoint
```

A template must not hardcode an integration-specific Jotform ID. The form markup
uses the runtime integration slug:

```php
<form
    data-jotform-bridge
    data-jotform-integration="<?php echo esc_attr($integration['slug']); ?>"
>
```

---

# Frontend JavaScript

Use vanilla JavaScript. Do not use jQuery. The JS has to work with no build
pipeline, and the production plugin ships a finished:

```text
assets/frontend.js
```

The form marker:

```html
<form
    data-jotform-bridge
    data-jotform-integration="contact"
>
```

The field marker:

```html
data-jotform-field="email"
```

The script has to:

* intercept the submit;
* collect the semantic fields;
* handle radio correctly;
* checkbox;
* select;
* multi-value fields;
* composite fields;
* block a repeated or double submit;
* send JSON;
* show validation errors;
* restore the submit state after an error.

It dispatches:

```text
jotformbridge:before-submit
jotformbridge:success
jotformbridge:error
```

It imposes no popup and no animation.

A redirect happens only where one is configured on the Integration, and only
from the data that came back in the success response. See `Success redirect`.

A theme must be able to build its own UX and to cancel the configured redirect
by calling `preventDefault()` on `jotformbridge:success`.

---

# The REST API

Namespace:

```text
jotform-bridge/v1
```

The submission route:

```text
```text
POST /wp-json/jotform-bridge/v1/submit/{integration}
```

For example:

```text
POST /wp-json/jotform-bridge/v1/submit/contact
```

Use the WordPress REST API:

```php
register_rest_route()
WP_REST_Request
WP_REST_Response
WP_Error
```

The frontend sends semantic data. Conceptually:

```json
{
    "fields": {
        "name.first": "John",
        "name.last": "Smith",
        "email": "john@example.com"
    }
}
```

The frontend never sends anything authoritative:

* no Jotform Form ID;
* no API key;
* no qid.

The backend resolves all of that itself:

```text
integration
→ configured Jotform form
→ normalized schema
→ Jotform qids
```

---

# Submission validation

Every value is checked server-side. Frontend validation is UX only.

The backend checks:

* the integration exists;
* a schema is available;
* the fields are allowed;
* required fields are present;
* the field type;
* the allowed options;
* the request size.

WordPress sanitization, for example:

```php
sanitize_text_field()
sanitize_textarea_field()
sanitize_email()
```

An email address is additionally checked for a valid format.

Select and radio values are checked against the permitted options where the
schema provides them, and so are checkbox and other multi-value fields.

Never trust a field type that came from the frontend request.

---

# SubmissionMapper

There is a separate SubmissionMapper, responsible for one conversion and nothing
else:

```text
semantic fields
→
Jotform submission payload
```

The REST controller contains no mapping business logic.

Composite fields need particular care:

```text
name.first
name.last
address.city
...
```

Check the current Jotform REST API format before implementing the mapper. Do not
guess the payload.

---

# REST responses

Success:

```json
{
    "success": true,
    "message": "Form submitted successfully."
}
```

Where the Integration is configured to redirect, the success response also
carries:

```json
{
    "success": true,
    "message": "Form submitted successfully.",
    "redirect": {
        "url": "https://example.com/thanks/",
        "delay": 0
    }
}
```

The `redirect` key is absent when no redirect is configured.

A validation failure:

```text
HTTP 422
```

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "email": "Invalid email."
    }
}
```

An upstream or Jotform error: an appropriate `5xx`.

Never return:

* credentials;
* API keys;
* internal filesystem paths;
* a raw exception stack;
* sensitive upstream detail.

---

# Spam protection

A public endpoint cannot be considered protected by a WordPress nonce. Do not
use a nonce as the only anti-spam protection on an anonymous form.

The submission pipeline has one extension point — the
`jotform_bridge_spam_check` filter (`Submission\SpamGuard`). A provider returns
`true` (allow), `false` (refuse with the default message) or a string (refuse
with that message), and must respect the verdict of the provider before it: when
`$allowed !== true`, return it unchanged.

The shipped providers register on that same filter, exactly as a third-party one
would:

| Provider | Priority | Value absent | Cost |
| --- | --- | --- | --- |
| `Guards\Honeypot` | 10 | allows | none |
| `Guards\ProofOfWork` | 15 | **refuses** | ~65k SHA-256 in the browser |
| `Guards\MinimumTime` | 20 | allows | none |
| `Guards\Turnstile` | 30 | **refuses** | an outbound request + a third-party script |

The difference in the "value absent" column is deliberate and has to be kept.
The honeypot and the timing check are hints in the markup that an older template
may not carry, so their absence is no reason to break a site. The proof of work
and Turnstile come from the plugin itself on every form it renders, so arriving
without them means the page's script was never executed.

Turnstile registers only when both constants are present: a check that always
fails open is worse than no check, because it looks like protection.

Anti-spam values travel in a separate `spam` container rather than in `fields`:
the validator checks every semantic path against the schema and rejects the ones
it does not know, so a token sent as a field would break every submission.

---

# Success redirect

Each Integration can be configured to redirect after a successful submission.
The redirect is configured per Integration, never globally, so two integrations
of the same Jotform Form may have different redirect targets.

## Configuration

```text
Success Action:   message | redirect
Redirect Page ID: WordPress page ID
Redirect Delay:   seconds, 0 by default
```

The admin picks an existing WordPress page or post from a list
(`wp_dropdown_pages()` or an equivalent controlled choice), not a free-text URL
input in this version.

## Authority

The backend decides the redirect target. The frontend never sends a redirect URL
in the submission request, and the backend resolves the `Redirect Page ID` into
a URL at the moment it answers.

Two reasons:

* the page URL may change after the Integration was saved;
* a frontend-provided URL is an open redirect vector.

## Validation

The redirect URL has to be internal to the site, checked with
`wp_validate_redirect()` or an equivalent host comparison against `home_url()`.

If the page is missing, in the trash, or not published:

* no redirect happens;
* the behaviour degrades to the ordinary success message;
* the admin is warned about the invalid redirect target on the Integration
  screen, in the same way template compatibility is reported.

A successful submission must never fail because of a broken redirect target.

## Frontend behaviour

When the success response carries a `redirect`, the script navigates after
dispatching `jotformbridge:success`.

The order is mandatory:

1. the form's success state;
2. dispatch `jotformbridge:success` — the form is still filled in, and
   `detail.fields` carries the submitted values;
3. clear the form (`reset()`, restart the timer, drop the proof of work);
4. redirect.

Clearing happens after the event on purpose: a theme's handler needs the
submitted values, and they cannot be read out of a form that has been emptied.

The `jotformbridge:success` event has to be cancelable as far as the redirect is
concerned: if the theme calls `preventDefault()`, no redirect happens and the
theme builds its own UX. The event's `detail` carries `redirect` so the theme can
make that decision.

The redirect uses `window.location.assign()`. With `delay > 0` it waits, so the
success message can be read, and the form stays disabled for the whole delay: a
second submit after a successful one is not acceptable.

## Limits

* A redirect applies only to a successful submission.
* Validation errors and upstream errors never redirect.
* Do not put submission data in the redirect URL's query string.
* Do not pass persistent identifiers through the URL.

---

# AutoRenderer

AutoRenderer builds semantic, accessible HTML from the Normalized Schema, using:

```html
<label>
<input>
<textarea>
<select>
<fieldset>
<legend>
```

with:

* correct input types;
* required;
* accessible IDs;
* error hooks;
* predictable CSS classes.

The base classes:

```text
.jfb-form
.jfb-field
.jfb-field--email
.jfb-error
.jfb-submit
```

Do not build a visual form builder.

Do not try to copy Jotform's design.
Do not add a heavy frontend CSS framework.

## Admin assets

No inline `<script>` or `<style>` in the admin views or in the notice classes.
The CSS and JS live in `assets/admin.css` and `assets/admin.js` and are enqueued
through `Admin\AdminAssets`, on the plugin's own screens only.

The reason is not only caching: an inline block is refused outright by any site
running a Content Security Policy, and the admin then breaks silently and half
way.

Behaviour is declared in the markup rather than wired up per screen:

```text
[data-jfb-toggle="<selector>"] + [data-jfb-toggle-value="<value>"]
[data-jfb-copy="<text>"] | [data-jfb-copy-from="<selector>"]
```

That way a PHP constant reaches the HTML through `esc_attr()` in an attribute
rather than through `esc_js()` in a script body, and a new row or a new copy
button needs no JavaScript at all.

---

# Storage and caching

Two things that are easy to confuse have to stay apart:

**Stored state** — not a cache, no TTL, never expires on its own, and written
only by an explicit action of the administrator:

* the connected form records (`jotform_bridge_connected_forms`, the
  **Connect form** button — one record per form an integration names, never a
  list of the account; see "Amendment: no account form list");
* the normalized schema (`jotform_bridge_schema_{formId}`, the **Sync Schema**
  button).

Nothing may overwrite these in passing: not a page view, not a submission, not
activation, not an upgrade. See "Amendment: manual schema synchronization".

**Cheap state**, which can be lost without consequence — transients and
in-request memos:

* rate limit buckets;
* duplicate and proof-of-work fingerprints;
* memoization within one request (`IntegrationRepository`, `FormRepository`,
  `TemplateRegistry`, `Settings`).

The template registry is deliberately **not** cached between requests — see
"Amendment: template discovery reads the theme on demand".

An ordinary page request must not call the Jotform API. A page with no Jotform
Bridge form on it must trigger neither a Jotform API call nor a scan of the
theme.

---

# Debug logging

Debug logging is:

```text
disabled by default
```

and can be switched on in the plugin settings.

It may log:

* API failure metadata;
* HTTP status;
* schema refresh failures;
* template validation problems;
* technical submission errors.

It must not log:

* the API key;
* passwords;
* full sensitive submission content, absent a real need.

---

# Admin UI

There is a top-level admin menu:

```text
Jotform Bridge
├── Integrations
└── Settings
```

## Settings

At a minimum:

```text
API Key (read-only: the state of the constant, or how to set it)
Region
Connection Status
Debug Logging
```

Actions:

```text
Save
Check Connection
```

`Check Connection` is the only action on this screen that contacts Jotform, and
it does exactly one thing: `GET /user`. There is no form list to load, and no
action anywhere that asks what forms an account has.

It kept a button of its own for a reason that is not obvious. Jotform answers a
form ID that does not exist, a form owned by another account and a wrong API key
with the same `401 You're not authorized to use (/form-id)` — verified against
the live API. So a failing `Connect form` cannot say which of the three it is.
`GET /user` names no form, so its success proves the key and leaves the ID as the
only suspect. Removing this button removes that distinction from the product.

## Integrations

The Integration UI:

```text
Name
Slug
Jotform Form ID  + the Connect form button
Rendering Mode
Template
Success Action
Redirect Page
Redirect Delay
```

`Jotform Form ID` is a text field, not a select: the ID is typed and resolved one
at a time by `Connect form`, which is the only action that asks Jotform whether a
form exists. It answers over admin-ajax rather than by posting the screen, so
nothing already typed into the editor is lost.

`Redirect Page` and `Redirect Delay` are shown only when
`Success Action = redirect`.

Also shown:

```text
Schema status
Template compatibility
Redirect target status
```

Actions:

```text
Connect form
Sync Schema
Send Test Submission
Delete
```

`Sync Schema` is the only action that asks Jotform for a form definition, and
always for one integration at a time. There is no `Rescan Templates`: templates
are read from the theme on demand.

The editor's primary action sits beside the page title, not at the foot of the
screen: the fields it saves are the first thing on the page, and the schema
table and starter template below them are reference material nobody should have
to scroll past in order to press Save. The destructive action stays at the
bottom, away from it.

Do not build a visual form builder.

---

# Developer diagnostics

The Integration screen shows the normalized schema as a table. Example columns:

```text
Label
Semantic Key
QID
Type
Required
Template Status
```

Do not make a developer open raw JSON. A raw response may be added in a
debug/developer mode where it is genuinely useful.

---

# Security

Always observe:

* capability checks in the admin;
* admin nonces for mutating admin actions;
* sanitization on input;
* validation;
* contextual escaping;
* no arbitrary file include;
* no path traversal;
* no API keys on the frontend;
* no client-trusted Jotform IDs.

Admin settings are reachable only by a user with a suitable capability, for
example:

```text
manage_options
```

The public submission endpoint validates everything itself.

---

# Escaping

Use contextual escaping:

```php
esc_html()
esc_attr()
esc_url()
```

and the other WordPress APIs.

Never pass user-editable admin values through as raw HTML.

A custom theme template is a trusted, developer-controlled PHP file and may
build its own HTML.

---

# Code architecture

Modern object-oriented PHP is preferred. Responsibilities, roughly:

```text
Api/
    JotformClient

Forms/
    FieldNormalizer
    FormSchema
    FormRepository

Integrations/
    IntegrationRepository

Templates/
    TemplateScanner
    TemplateRegistry
    TemplateValidator

Rendering/
    CustomTemplateRenderer
    AutoRenderer
    FormRenderer

Submission/
    SubmissionValidator
    SubmissionMapper

Rest/
    SubmissionController

Admin/
    SettingsPage
    IntegrationsPage
    AdminAssets
```

That is a guide, not an instruction to create meaningless empty classes.

Do not build:

* a giant god class;
* a giant functions.php-style plugin file;
* business logic inside admin views;
* arbitrary static globals;
* direct `$_POST` access inside domain services.

---

# WordPress hooks

Add extension points only where they are genuinely useful. Do not add hooks for
the sake of the count.

The list of them lives in `HOOKS.md`, which is generated from the docblocks
above the `apply_filters()` and `do_action()` calls themselves by
`bin/generate-hooks.php`, and checked in CI. It is not repeated here: this file
carried seven of the seventeen that exist, which is the drift the generated
reference was written to stop.

A new hook needs nothing but a docblock above its call — summary, prose if the
contract needs explaining, and one `@param` per argument. `composer hooks`
picks it up; `composer hooks:check` fails if somebody adds one and forgets.

---

# MCP

The project may run in two environments, and the tools available differ between
them.
## Codex

Project-scoped MCP servers are configured in:

```text
.codex/config.toml
```

Available:

```text
wordpress-playground
playwright
jotform
```

## Claude Code

Project-scoped MCP servers are configured in `.mcp.json` at the repository root,
and project settings live in `.claude/settings.json`. The same three servers are
declared there, so the names `wordpress-playground`, `playwright` and `jotform`
mean the same thing in both environments.

Two of them still need something from the user, and the agent must not try to
work around it:

```text
Jotform MCP    → needs the connector to be authorised by the user.
                 Never ask for tokens, codes or a callback URL.
                 If it is not authorised, say so and continue without it.
Playwright MCP → needs the browser package; where it is unavailable, the
                 built-in browser tools (mcp__chrome-devtools__*) serve the
                 same purpose.
```

If WordPress Playground cannot start, the alternatives, in order:

* a local WordPress installation, if one exists;
* `wp-env` / `wp-now` through Bash, where Docker or Node are available;
* `@wp-playground/cli` through `npx`, where the network is available;
* if none of these exist — stay with the unit tests and state plainly in the
  report that no WordPress runtime verification was performed.

## The general rule

Tools are used for real verification, not because they happen to be available.

If a tool is unavailable, see the `MCP failure policy` section. Never present an
unperformed check as performed, and never substitute reading your own code for
actually exercising it.

---

# WordPress Playground MCP

Use it for WordPress runtime verification:

* plugin activation;
* fatal errors;
* hooks;
* REST routes;
* options;
* transients;
* PHP execution;
* the shortcode;
* rendering;
* lifecycle;
* runtime smoke tests.

Where a change depends on the WordPress runtime, do not stop at reading the PHP.
Exercise it in WordPress Playground where that is possible.

---

# Playwright MCP

Use it for browser and UI verification:

* wp-admin;
* Settings;
* Integrations;
* selects;
* buttons;
* validation messages;
* the frontend form;
* JS behaviour;
* REST submission;
* success and error states;
* double submit prevention.

Where a change touches the UI or frontend behaviour, run a smoke test through
Playwright.

---

# Jotform MCP

Use it primarily as a read and verification tool. It is useful for:

* listing test forms;
* checking that a form exists;
* checking submissions;
* end-to-end verification.

Do not use the Jotform MCP server as a substitute for the official REST API
documentation.

By default, do NOT:

* delete forms;
* modify production forms;
* delete submissions;
* change real production data.

Use only an explicitly test or development form for write tests. If no such form
exists, do not perform a live write without explicit permission.

---

# End-to-end verification

The ideal E2E flow:

```text
Playwright
    ↓
WordPress form
    ↓
WordPress REST API
    ↓
Jotform Bridge
    ↓
Jotform REST API
    ↓
Jotform
    ↓
Jotform MCP verification
```

Where a live Jotform write cannot be performed safely:

* exercise the mapper's unit and integration tests;
* exercise the REST validation;
* use fixtures and mocks;
* state plainly that no live upstream write was verified.

---

# MCP failure policy

If a particular MCP server is unavailable:

* do not break the implementation;
* run whatever alternative check is available;
* state explicitly in the final report what was not verified.

Never claim an E2E test passed when it was not run.

---

# Tests

Critical domain logic has to be testable separately from the WordPress UI.

Test priority:

1. FieldNormalizer
2. semantic key generation
3. collision detection
4. TemplateScanner
5. TemplateValidator
6. SubmissionValidator
7. SubmissionMapper

Use fixtures with realistic Jotform API responses. Unit tests must not require a
live Jotform API.

## The stack

Fixed:

```text
PHPUnit ^9.6
brain/monkey ^2.6
mockery/mockery (transitively, through brain/monkey)
phpstan/phpstan ^2.1
szepeviktor/phpstan-wordpress ^2.0
```

PHPUnit 9.x because PHPUnit 10+ requires PHP 8.1+, and this project supports
PHP 8.0.

Brain Monkey is what lets us mock WordPress functions (`get_option`,
`wp_remote_get`, `sanitize_text_field`, `apply_filters` and so on) without
loading WordPress.

All of it is a **dev dependency**. None of it ships in the release ZIP.

## Layout

```text
composer.json
phpunit.xml.dist                # the unit suite
phpunit.integration.xml.dist    # the integration suite
phpstan.neon.dist               # static analysis; bin/phpstan-bootstrap.php
                                # tells it what the main plugin file defines
tests/
├── bootstrap.php
├── TestCase.php          # base class with Brain Monkey setUp/tearDown
├── Unit/
│   ├── Forms/
│   ├── Templates/
│   ├── Submission/
│   ├── Admin/
│   └── Api/
├── Integration/
│   ├── bootstrap.php     # loads WordPress and checks the plugin booted
│   ├── config.php        # the constants both processes have to agree on
│   ├── install.php       # installs the site once, in its own process
│   ├── Runtime.php       # one fresh request per test
│   ├── TestCase.php      # base class: no network, wp_die() and redirects throw
│   ├── Admin/
│   ├── Rest/
│   └── Templates/
└── Fixtures/
    └── Jotform/          # sanitized Jotform API responses, shared by both
```

Composer PSR-4:

```text
JotformBridge\       → jotform-bridge/src/
JotformBridge\Tests\ → tests/
```

## Running them

```bash
composer install
composer test              # the unit suite; no WordPress, no network
composer test:integration  # the integration suite; downloads WordPress once
```

`composer test` has worked since stage 1, even when there was almost nothing to
run. Setting it up was not deferred.

## Two suites

The unit suite stubs WordPress with Brain Monkey and asserts about one class at
a time. It cannot see the seams *between* classes, and that is exactly where the
defects of August 2026 were: a hidden field read unconditionally from `$_POST`,
two admin screens describing a directory and a button that no longer existed, an
auto-rendered form posting an empty body. Each of those is invisible to a test
that constructs one class and calls one method, so there is a second suite
beside the first.

The two cannot share a process — one defines `get_option()` as a stub, the other
loads the real one — which is why the second has a configuration of its own
rather than another `<testsuite>` entry in the first.

The integration suite runs WordPress core on the official SQLite drop-in:

* `bin/install-wp.sh` downloads both into `.wordpress/`, which is git-ignored,
  and symlinks the plugin into `wp-content/plugins/` so the suite always tests
  the working tree and never a copy of it;
* `tests/Integration/install.php` installs the site once, in its own process
  (WP_INSTALLING cannot be switched off again), and the result is kept as a
  pristine database every later run is restored from;
* the plugin is active in the options table, so `wp-settings.php` includes it
  the way a site does, through its own autoloader;
* `Runtime::newRequest()` gives each test a new request: the plugin's storage is
  emptied, the callbacks bound to the previous services are removed, the
  composition root is discarded and the plugin is booted again. The repositories
  memoize within a request — correctly, since nothing else writes their storage
  inside one — so a suite that kept the first request's objects would be
  asserting against answers cached before it set anything up.

No database server, no Docker, no `wp-env`: `composer test:integration` is the
whole thing, on a laptop and in CI alike.

What the suite has to keep proving:

1. the REST route through `WP_REST_Request` against the registered route —
   the permission callback, the JSON body, the status codes and the headers,
   not the pipeline called directly;
2. the `admin_post_` and `wp_ajax_` handlers, with and without a valid nonce and
   with and without the capability, asserting that the guard actually fires and
   that a refused action changed nothing;
3. a template rendered from a real theme directory, child theme priority
   included, and a path outside the roots refusing to resolve;
4. activation, upgrade and uninstall against a real options table — the legacy
   purges above all, because they run once per site and therefore never run on a
   developer's machine;
5. the admin screens rendered, asserting they do not describe features the
   plugin no longer has. Three stale instructions reached users that way, and
   none of the three was a bug in any class.

The network is unreachable from it. `WP_HTTP_BLOCK_EXTERNAL` is defined and any
request a test did not answer through `mockHttp()` throws, so the upstream
boundary is always a fixture: a live Jotform write cannot happen by accident,
whatever the local `.env` contains.

## How to write them

Domain tests must not:

* load WordPress;
* touch the network;
* write to the filesystem outside a temporary directory;
* depend on execution order.

`TemplateScanner` tests may create temporary files through `sys_get_temp_dir()`
and must clean up after themselves.

Jotform API fixtures have to be sanitized: no real API keys, email addresses,
names or phone numbers.

Where the shape of a Jotform response for some field type is unknown, check the
official documentation first, or read a real schema read-only, and only then
write the fixture. An invented fixture is worse than a missing test, because it
sets a wrong assumption in stone.

## Lint

Two analysers, because they overlap barely at all:

```text
squizlabs/php_codesniffer + wp-coding-standards/wpcs   composer lint
phpstan/phpstan + szepeviktor/phpstan-wordpress        composer analyse
```

PHPCS reads the shape of the source — escaping, nonces, prefixes, the text
domain, the style — and does not know what a variable holds. PHPStan reads
types across call boundaries: a value that can be null reaching a parameter
that cannot take it, and a docblock that no longer describes the code under it.

Both are configured the same way and for the same reason: what is switched on
is what can catch a defect, and everything switched off says why, in the
ruleset itself. `phpcs.xml.dist` is deliberately not the full `WordPress`
standard; `phpstan.neon.dist` is level 8 with four suppressions, each carrying
the argument for itself. A linter whose output has to be ignored teaches people
to ignore linter output.

The one worth knowing about without reading the file: PHPStan is told not to
report a redundant `is_array()` or `is_string()` on a value that crossed a
trust boundary. `phpstan-wordpress` types the return of `apply_filters()` from
the `@param` tags above the call, and those describe what the plugin passes
*in* — what comes back is whatever a third-party callback returned. Those
guards are the only thing between another plugin's mistake and a fatal error
in ours, and PHPStan reads every one of them as dead code.

Neither blocks a stage on its own. Tests come before the linter.

---

# Workflow for a change

The stages in `prompts/` are finished, but the order of work is unchanged.

Before changing anything:

1. Read this `AGENTS.md`, including the `Amendment:` sections at the end.
2. Study the code that already exists.
3. Do not rewrite working architecture without an objective need.
4. Decide the minimum scope of the change at hand.
5. Implement it.
6. Run whatever tests and lint are available.
7. Run the matching MCP verification.
8. Fix what that turned up.
9. Only then treat the work as finished.

---

# No premature implementation

Where the current prompt describes one particular stage:

* do not implement future stages in full;
* a minimal extension point is fine;
* a large amount of code "for later" is not.

The goal is small, verifiable increments.

---

# The report after each piece of work

Afterwards, provide:

```text
What was implemented
Which files changed
Which architectural decisions were taken
Which tests were run
Which MCP checks were run
What could not be verified
What is left for next time
```

Do not write simply:

```text
Done
Implementation complete
```

with no verification detail.

---

# The project's core invariants

These rules cannot be broken without an explicit change of requirements:

1. WordPress 6.4+.
2. PHP 8.0+.
3. No Sage or Blade in this version.
4. The plugin is standalone.
5. A custom template does not know the Jotform Form ID.
6. A template does not know a Jotform qid.
7. The Form ↔ Template binding lives in the Integration.
8. The frontend uses semantic `data-jotform-field`.
9. The backend is the authoritative source of the mapping.
10. The API key exists server-side only, and only in the `wp-config.php`
    constant; it is not in the database.
11. The Jotform REST API is not called on every page view.
12. Every submission is validated server-side.
13. An arbitrary filesystem path is never rendered.
14. One Jotform Form may have several integrations and templates.
15. The custom template is the main scenario.
16. AutoRenderer is built on top of the finished Normalized Schema.
17. The official documentation is checked before relying on a Jotform payload
    format.
18. MCP is used for real verification wherever that is possible.
19. The redirect target comes from the Integration, is resolved by the backend
    and must be an internal URL; the frontend never dictates where a redirect
    goes.
20. The schema is synchronized manually only, by the Sync Schema button, and for
    one integration at a time. No TTL, no cron, no fetch on a frontend path:
    rendering and submission read the stored schema or refuse. See "Amendment:
    manual schema synchronization".
21. The plugin stores no submission values, no IP addresses and no visitor
    identifiers of any kind. Submitted data lives in Jotform and nowhere else.
    What reaches the options table is configuration, form definitions and
    anonymous defensive counters (a hashed address in a rate-limit bucket, the
    number of submissions sent today).
22. The plugin does not track how much of the account's monthly allowance has
    been spent and does not poll `GET /user/usage`. The circuit breaker works
    from the site's own history and from Jotform's own refusal. See "Amendment:
    no account usage tracking".

---

# Amendment: manual schema synchronization

A clarification to the "Schema refresh" and "Rendering" sections, decided after
stage 7.

The Normalized Schema is no longer a cache but stored state:

* it lives in the option `jotform_bridge_schema_{formId}` (`autoload = false`),
  not in a transient, and has no TTL;
* it is written by exactly one action — **Sync Schema**, a separate button per
  integration (on the list and in the editor);
* it is written by nothing else: not by saving an integration, not by
  activation, not by an upgrade, not by a page view, not by a submission;
* deactivation and upgrade do not delete it — only uninstall does. An upgrade
  marks the schema as synced by an older plugin version and shows that in the
  admin, but does not touch the data.

The consequences that have to hold:

1. `SchemaRepository::get()` never makes an HTTP request. An unsynced schema is
   a failure with the code `schema_not_synced`.
2. Rendering a form with no synced schema renders nothing — the administrator is
   shown why — rather than trying to fetch one.
3. A submission with no synced schema answers 503 and does not contact Jotform.
4. The connected form records follow the same rule: an option with no TTL,
   written only by the **Connect form** button. (This clause used to describe an
   account-wide form list; see "Amendment: no account form list".)

The price is accepted deliberately: a form edited in Jotform keeps working from
the previous definition until the site owner presses Sync Schema.

---

# Amendment: template discovery reads the theme on demand

A clarification to the "TemplateScanner" and "Storage and caching" sections that
overturns the requirement to cache the registry, and the `Rescan Templates`
button with it.

The registry is read from the theme on demand and memoized within one request
only. The cross-request cache was removed deliberately:

* a cached registry meant a developer could drop a file into the theme, not see
  it in the list, and have no way of knowing why;
* worse, an edited template left the compatibility report describing the
  previous version of the file — the admin lying about exactly the thing that
  report exists for;
* the `Rescan` button treated the symptom: it required a person to remember a
  cache they never asked for.

The price is accepted deliberately and has to stay bounded:

1. Discovery reads only the header of each file (`HEADER_BYTES`, 8 KB) — a
   `scandir()` of two directories plus a short `fread` per file.
2. Parsing a whole file (`TemplateScanner::fields()`) is never called on a
   frontend path, only by the compatibility report in the admin.
3. A page with no form of ours on it scans nothing.

If profiling shows that is not enough, the right next step is an object-cache
layer invalidated by the directory's mtime — not the return of the `Rescan`
button. The admin has to keep reading the state of the files directly.

---

# Amendment: no account usage tracking

Overturns the part of the "Jotform API" section that assumed reading
`GET /user/usage`, and removes account monthly-quota tracking from the product.

Removed:

* `JotformClient::getUsage()` and the snapshot of what had been spent;
* the `Monthly Submission Allowance` setting;
* the "used N of M" warning in the admin;
* the ceiling derived from the remaining allowance (`REASON_QUOTA`);
* the feature-flag mechanism itself (`Support\Features`), which existed only for
  this and for the hidden feature in the next amendment.

The reasoning: Jotform reports how much has been spent but not what the plan
allows. So the size of the allowance has to be asked of the site owner, and the
spend has to be polled and kept as a snapshot. That is a second source of truth,
stale by construction, in service of a warning that is in any case less reliable
than Jotform's own refusal.

What stays, and has to stay:

1. **The daily ceiling** derived from the site's own history: six times the
   median of the last seven days, never below `MIN_DAILY`. It needs no knowledge
   of the account and makes no requests.
2. **The reaction to Jotform's refusal** — `ERROR_FORM_QUOTA` and
   `ERROR_API_LIMIT` trip the breaker (`REASON_UPSTREAM_QUOTA`,
   `REASON_UPSTREAM_API_LIMIT`). That is not tracking; it is answering a refusal
   that has already happened.
3. **`limit-left`** from Jotform's own response: it arrives on its own, costs
   nothing, and is the only warning before the API stops answering.

---

# Amendment: no submission tally

Removes the per-integration submission statistics (`Support\Stats`) and the
screens that read them.

The machinery was written, covered by tests and hidden behind a feature flag,
and was never switched on. Keeping a switched-off feature in a release means
paying for it in code, in translated strings and in template branches, and
getting nothing back.

Removed:

* the `Support\Stats` class and the `jotform_bridge_stats` option;
* the "Last 7 days" column on the integrations list;
* the "Submissions" block and "Fields visitors get wrong most often" in the
  editor;
* the `SubmissionPipeline::count()` wrapper, which existed only in order to
  count.

The problem the statistics addressed remains unsolved, and is acknowledged as
such: a form that quietly refuses real people looks, from the outside, exactly
like a working one. For now the only way to see it is to switch on debug
logging. If the question is revisited, what comes back must not be the same
counter in an option: writing a whole option on every submission loses data
under concurrent submissions. See TODO.

---

# Amendment: the elapsed-time guard refuses a forged measurement

A clarification to "Spam protection", recorded because the original wording made
the bug look intentional.

`Guards\MinimumTime` reads anything outside its plausible range as "not
measured" and lets it through. That is right above the ceiling — a tab left open
overnight is not evidence of anything — and wrong below zero: the frontend
script clamps its own measurement at zero, so a negative number cannot come from
a browser that ran it.

The two cases are therefore separate:

* `t > MAX_SECONDS` → treated as absent, allowed;
* `t < 0` → a forgery, refused.

The general rule behind it: "the value is absent" and "the value could not have
been produced by our own script" are different states, and a guard that conflates
them can be stepped over with one character.

---

# Amendment: no account form list

Overturns the parts of "The Jotform API", "Storage and caching" and "Admin UI"
that assumed the plugin keeps a copy of every form on the Jotform account, and
removes account-wide form discovery from the product.

Removed:

* `JotformClient::getForms()` and `FORMS_PAGE_LIMIT`, so `GET /user/forms` is
  never called;
* the options `jotform_bridge_forms`, `jotform_bridge_forms_meta` and
  `jotform_bridge_forms_hidden`, deleted on upgrade and on activation;
* the **Sync with Jotform** button, the Jotform Forms table on the settings
  screen, and the **Remove from list** action with it;
* the form `<select>` in the integration editor;
* the refusal to save an integration whose form is not in the stored list.

Added:

* `JotformClient::getForm()` — `GET /form/{formID}`, one form by ID;
* `jotform_bridge_connected_forms`, one record per form an integration names:
  id, title, status, Jotform's `updated_at`, and when it was checked;
* **Connect form**, a button beside the Jotform Form ID field in the integration
  editor, answering over `wp_ajax_jotform_bridge_connect_form`;
* **Check Connection** on the settings screen, which is the old button reduced
  to the `GET /user` half.

The reasoning: the account list was the largest thing the plugin stored and the
least of it was used — three integrations kept every form on the account, and a
shared agency account holds hundreds. It was fetched with `limit=1000` and no
paging, so a large account was silently truncated and the missing forms could
never be selected at all. **Remove from list** and its option existed only to
hide rows from a list nobody had asked for. Fetching by ID makes all three
problems not smaller but absent.

The price is accepted deliberately: the site owner has to find the form ID in
Jotform rather than pick a title from a list. That is one copy-paste per
integration, against a store that grows with the site instead of with the
account.

What has to hold:

1. **Nothing anywhere asks what forms an account has.** Not a page view, not the
   editor, not activation. `Connect form` and `Sync Schema` name a form ID; every
   other Jotform call is `GET /user` or a submission.
2. **A record is a label, never authority.** The form ID lives on the Integration
   and is what rendering and submission use. A missing record costs a title in
   the admin and nothing else, which is why a failed `Connect form` leaves what
   is stored alone, and why a settings save no longer flushes it.
3. **An unconnected form ID is a warning, not a refusal.** The field is free text
   now; refusing to store digits somebody typed, because a button beside them was
   not pressed, would make the editor feel broken. Nothing goes quietly wrong
   either way — an integration with no synced schema does not render at all.
4. **Connect form is asynchronous on purpose.** It resolves one field of a form
   still being filled in. A redirect would either discard the rest of the editor
   or have to save it, and neither is what a button beside a text field should
   mean. Without JavaScript the button does nothing and the field still saves,
   which is what point 3 buys.
5. **The 401 is ambiguous and must not be explained away.** Jotform answers a
   nonexistent form, another account's form and a bad API key identically. The
   admin names all three possibilities and points at Check Connection; it never
   claims to know which one happened.
