# Jotform Bridge

> **Status: target specification, not shipped documentation.**
>
> No plugin code exists in this repository yet. This document describes the
> intended behaviour of the finished plugin and is used as a design reference
> while stages 1–6 (see `prompts/`) are implemented.
>
> Code examples here are **illustrative**. Where an example conflicts with the
> actual Jotform REST API — composite field child names in particular — the API
> wins, and this file must be corrected rather than the implementation bent to
> match it.
>
> Stage 6 rewrites this file into real user documentation that reflects what was
> actually built. Do not treat it as complete until then.

Jotform Bridge is a standalone WordPress plugin that uses Jotform as a headless backend for custom forms.

It allows developers to fully control the form markup and frontend experience in WordPress while using Jotform for:

* submissions;
* email notifications;
* integrations;
* form structure and configuration;
* submission storage.

## Requirements

* WordPress 6.4+
* PHP 8.0+

The plugin does not depend on:

* ACF;
* Elementor;
* jQuery;
* Sage or Blade;
* frontend frameworks.

---

## Installation

Install the plugin normally through WordPress:

```text
Plugins
→ Add New
→ Upload Plugin
→ Activate
```

Then open:

```text
Jotform Bridge
→ Settings
```

and configure the Jotform API connection.

The API key can also be defined in `wp-config.php`:

```php
define('JOTFORM_API_KEY', 'your-api-key');
```

When the constant is defined, it takes priority over the value stored in WordPress.

The API key is never exposed to the frontend.

---

## Integrations

Forms are connected through local **Integrations**.

Example:

```text
Name: Contact Form
Slug: contact
Jotform Form: Contact Us
Rendering: Custom Template
Template: Contact Form
```

Theme code only uses the local integration slug:

```php
echo jotform_form('contact');
```

or:

```text
[jotform_form id="contact"]
```

Jotform Form IDs do not need to appear in theme code.

---

## Custom Templates

Custom templates are stored inside the active theme:

```text
your-theme/
└── forms/
```

Example:

```text
forms/contact.php
```

A template must contain the Jotform Bridge header:

```php
<?php
/*
Jotform Template Name: Contact Form
Jotform Template Slug: contact
*/
?>
```

Do not add the Jotform Form ID to the template.

The relationship between a Jotform form and a template is configured through the WordPress admin.

After creating or changing templates, run:

```text
Jotform Bridge
→ Rescan Templates
```

---

## Custom Template Example

```php
<?php
/*
Jotform Template Name: Contact Form
Jotform Template Slug: contact
*/
?>

<form
    class="contact-form"
    data-jotform-bridge
    data-jotform-integration="<?php echo esc_attr($integration['slug']); ?>"
>
    <label for="contact-first-name">
        First name
    </label>

    <input
        id="contact-first-name"
        type="text"
        data-jotform-field="name.first"
    >

    <label for="contact-email">
        Email
    </label>

    <input
        id="contact-email"
        type="email"
        data-jotform-field="email"
    >

    <label for="contact-message">
        Message
    </label>

    <textarea
        id="contact-message"
        data-jotform-field="message"
    ></textarea>

    <div
        data-jotform-errors
        aria-live="polite"
    ></div>

    <button type="submit">
        Submit
    </button>
</form>
```

---

## Semantic Fields

Templates do not use internal Jotform Question IDs.

Instead of:

```html
<input name="q7">
```

use semantic field identifiers:

```html
<input data-jotform-field="email">
```

Composite fields are supported through paths:

```html
<input data-jotform-field="name.first">
<input data-jotform-field="name.last">
```

For example, address fields may look like:

```html
<input data-jotform-field="address.street">
<input data-jotform-field="address.city">
<input data-jotform-field="address.state">
<input data-jotform-field="address.zip">
```

> The exact child names for composite fields are derived from the Jotform form
> schema, not invented. The address example above is a placeholder written before
> the schema was inspected; the real sub-field identifiers are established in
> stage 2 and documented here afterwards.

Jotform Bridge maps these semantic identifiers to the correct Jotform Question IDs and submission structure on the server.

---

## Template Validation

Jotform Bridge compares custom templates against the current Jotform form schema.

Possible states:

```text
Compatible
Compatible with warnings
Invalid
```

If a required Jotform field is missing from the template, the integration is considered invalid.

After modifying a form in Jotform, use:

```text
Refresh Schema
```

to retrieve the latest schema and revalidate the template.

---

## Rendering Modes

Jotform Bridge supports two rendering modes.

### Custom Template

Uses a PHP template from the active WordPress theme.

This is the primary mode for fully custom form markup and design.

### Auto Generate

Automatically generates semantic HTML from the normalized Jotform schema.

The generated markup is intentionally minimal and can be styled by the active theme.

---

## REST API

Form submissions are sent through the WordPress REST API:

```text
POST /wp-json/jotform-bridge/v1/submit/{integration}
```

Example:

```text
POST /wp-json/jotform-bridge/v1/submit/contact
```

The frontend submits semantic fields only.

The server handles:

```text
Integration
→ Schema
→ Validation
→ Jotform Mapping
→ Jotform API
```

The browser does not provide authoritative Jotform Form IDs, Question IDs, or API credentials.

---

## JavaScript Events

Jotform Bridge dispatches frontend events that can be used for custom UX, analytics, redirects, or animations:

```text
jotformbridge:before-submit
jotformbridge:success
jotformbridge:error
```

The plugin does not force a specific success popup, redirect, or animation.

---

## Caching

The plugin caches:

* Jotform form lists;
* Jotform form schemas;
* normalized schemas;
* custom template registry.

Jotform API requests are not performed on every frontend page load.

Use the following actions when data needs to be refreshed:

```text
Refresh Forms
Refresh Schema
Rescan Templates
```

---

## Custom Template Paths

The default template directory is:

```text
/forms/
```

Additional template directories can be registered with:

```php
add_filter('jotform_bridge_template_paths', function (array $paths): array {
    $paths[] = get_stylesheet_directory() . '/components/forms';

    return $paths;
});
```

---

## Security

Jotform Bridge follows these core security rules:

* Jotform API credentials remain server-side.
* All submissions are validated server-side.
* Frontend field types and Jotform IDs are never trusted.
* Only registered theme templates can be rendered.
* Arbitrary filesystem paths are not supported.
* Custom templates use semantic fields instead of Jotform Question IDs.

---

## Supported Fields

The plugin is designed to support common Jotform fields such as:

* text;
* textarea;
* email;
* phone;
* full name;
* address;
* dropdown/select;
* radio;
* checkbox;
* number.

More complex Jotform widgets may require additional support.

Unsupported fields are reported through integration diagnostics instead of being silently mapped to an incorrect field type.

---

## Development

Project-specific Codex instructions are stored in:

```text
AGENTS.md
```

Project-scoped MCP configuration is stored in:

```text
.codex/config.toml
```

Development verification may use:

* WordPress Playground MCP for WordPress runtime testing;
* Playwright MCP for admin and frontend browser testing;
* Jotform MCP for read-only inspection and end-to-end submission verification.
