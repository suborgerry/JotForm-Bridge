=== Jotform Bridge ===
Contributors: suborgerry
Tags: jotform, forms, contact form, headless, custom form
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Jotform as a headless form backend: your own HTML on the site, submissions and data on Jotform.

== Description ==

Jotform Bridge lets you keep writing the form markup yourself — your classes, your
layout, your accessibility decisions — while Jotform keeps doing what it is good
at: storing submissions, sending notifications and running its own integrations.

Nothing about Jotform leaks into your theme. Templates address fields by readable
identifiers such as `email` or `full_name.first`, never by Jotform question IDs,
and never by a form ID. The binding between a local name and a Jotform form lives
in an **integration**, which is a small record in the WordPress admin.

= How it works =

1. Connect the site to your Jotform account with an API key.
2. Create an integration: a slug such as `contact`, the Jotform form it submits
   to, and how it should be rendered.
3. Render it with `<?php echo jotform_form('contact'); ?>` or `[jotform_form
   id="contact"]`.

Two rendering modes are available:

* **Custom template** — a plain PHP file in your theme's `/forms/` directory.
  This is the main use case.
* **Auto** — markup generated from the Jotform form definition, so an
  integration can go live before anybody has written a template.

Both modes post to the same plugin REST endpoint, and both are validated
server-side against the cached Jotform form definition before anything is
forwarded.

= After a submission =

Each integration decides on its own what a successful submission does: show the
success message in place, or send the visitor to a page of this site after an
optional delay. The target is picked from the site's published pages and stored
as a page ID, so a changed permalink takes effect at once and no URL from a
request can ever be redirected to. If the chosen page is deleted or unpublished,
the submission still succeeds and the success message is shown instead.

= What stays on the server =

The Jotform API key is never printed into HTML, never localized into JavaScript,
never returned from the REST endpoint and never written to a log. The frontend
only ever sees your own markup, your integration slug and the plugin's own
endpoint.

= Supported Jotform field types =

Short text, long text, email, phone, number, dropdown, radio, checkbox group,
and the composite Full Name and Address fields (addressed through their
sub-fields, e.g. `full_name.first`).

Anything else — file upload, signature, payment, date/time pickers, matrix,
widgets — is reported in the admin and left out of the form rather than rendered
half-way. A field whose value would be dropped on the way to Jotform must never
be presented to a visitor.

= No build step =

Plain vanilla JavaScript, no jQuery, no Node, no Composer install after
unzipping. The plugin ships its own autoloader.

== Installation ==

1. Upload the ZIP through **Plugins → Add New → Upload Plugin**, then activate.
2. Go to **Jotform Bridge → Settings**, paste your Jotform API key, pick the API
   region and save. Use **Test Connection** to confirm, then **Refresh Forms**.
3. Go to **Jotform Bridge → Integrations**, add an integration, and pick its
   Jotform form and rendering mode.

Instead of storing the key in the database you can define it in `wp-config.php`:

`define( 'JOTFORM_API_KEY', '...' );`

The constant wins over the stored option, the admin screen shows that the key is
set externally, and the value is never displayed.

= API region =

An EU or HIPAA Jotform account answers only on its own API host. Pick the
matching region in the settings, otherwise every call fails with a redirect
response. A custom base URL is available for enterprise installations.

== Frequently Asked Questions ==

= Does my theme need the Jotform form ID? =

No, and it must not have it. Theme code only ever names an integration slug.

= Can two integrations use the same Jotform form? =

Yes. That is the point of integrations: `consultation`, `consultation-popup` and
`consultation-footer` can all submit to one Jotform form with three different
templates.

= Where do templates live? =

In a `forms/` directory inside your theme, e.g.
`wp-content/themes/your-theme/forms/contact.php`. A child theme overrides a
parent template with the same slug. Templates are discovered by reading their
header — they are never executed during discovery.

= Does uninstalling delete my integrations? =

No. Deleting the plugin removes only the caches. If you want a full removal,
tick **Delete the integrations, the settings and the stored API key when the
plugin is deleted** on the settings screen first.

= Is spam protection included? =

No provider ships with the plugin, but every submission passes through the
`jotform_bridge_spam_check` filter immediately before it is sent upstream, so a
Turnstile or reCAPTCHA check can be added without touching the plugin. An
identical submission from the same visitor is refused for 30 seconds, so a
double click cannot create two Jotform submissions.

== Screenshots ==

1. The integrations list with per-integration compatibility and redirect status.
2. The integration editor: Jotform form, rendering mode, template and success action.
3. The settings screen: API key, region and diagnostics.

== Changelog ==

= 0.1.0 =
* First release: integrations, custom templates, automatic rendering, server-side
  validation, submission mapping to Jotform, per-integration success redirect,
  admin diagnostics.
