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

1. Connect the site to your Jotform account with an API key defined in
`wp-config.php`.
2. Create an integration: a slug such as `contact`, the Jotform form it submits
   to, and how it should be rendered.
3. Render it with `<?php echo jotform_bridge_render('contact'); ?>` or `[jotform_form
   id="contact"]`.

Two rendering modes are available:

* **Custom template** — a plain PHP file in your theme's `/forms/` directory.
  This is the main use case. Templates are read from the theme as you go: add or
  edit a file and it is picked up immediately, with nothing to refresh.
* **Auto** — markup generated from the Jotform form definition, so an
  integration can go live before anybody has written a template.

Both modes post to the same plugin REST endpoint, and both are validated
server-side against the synced Jotform form definition before anything is
forwarded.

The form definition is synchronized manually, per integration, with the **Sync
Schema** button. Nothing expires and nothing is fetched in the background, so no
page view ever waits on the Jotform API.

= After a submission =

Each integration decides on its own what a successful submission does: show the
success message in place, or send the visitor to a page of this site after an
optional delay. The target is picked from the site's published pages and stored
as a page ID, so a changed permalink takes effect at once and no URL from a
request can ever be redirected to. If the chosen page is deleted or unpublished,
the submission still succeeds and the success message is shown instead.

= What stays on the server =

The Jotform API key lives in `wp-config.php` and never reaches the database. It
is never printed into HTML, never localized into JavaScript, never returned from
the REST endpoint and never written to a log. The frontend
only ever sees your own markup, your integration slug and the plugin's own
endpoint.

= Protecting the endpoint =

Going headless means the form no longer sits behind Jotform's own defences, so
the plugin brings its own. Four are on from the start and cost a visitor
nothing: a honeypot field, a minimum time between opening a form and sending it,
a rate limit per visitor address, and a small proof of work.

The proof of work is what covers the gap the others leave. Before a form is
sent, the browser has to find a number that makes a SHA-256 hash start with a
run of zero bits — roughly 65,000 hashes, a fraction of a second, and it runs in
the background while the visitor is still typing. Nobody is asked to identify
themselves, click anything, or talk to a third party. Sending one submission
costs nothing worth measuring; sending a hundred thousand costs real machine
time, which is the entire business model of spam.

It is also the only marker whose absence is refused. A submission with no proof
did not run the plugin's script at all, which is exactly what posting straight
to the endpoint looks like. Above them sits a circuit breaker that
stops sending when a day's traffic is far above the site's normal — two hundred
submissions a day, or six times the recent median once there is one to compare
against. It also stops if Jotform itself reports the account is out of
allowance, because spending that allowance switches off every form on the
account, embedded ones included, until it resets.

Cloudflare Turnstile is optional and is the only layer that stops something
driving a real browser. Two constants in `wp-config.php` enable it:

`define( 'JOTFORM_BRIDGE_TURNSTILE_SITE_KEY', '...' );`
`define( 'JOTFORM_BRIDGE_TURNSTILE_SECRET', '...' );`

Flush your page cache after enabling it. A submission without a challenge token
is refused, and pages cached before the change do not carry the widget.

If the site sits behind a CDN or reverse proxy, tell the plugin which forwarded
header to believe, or every visitor will look like the proxy:

`define( 'JOTFORM_BRIDGE_TRUSTED_PROXY_HEADER', 'CF-Connecting-IP' );`

Custom templates print `$honeypot` and `$turnstile`; automatically rendered
forms include both already.

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
2. Add your Jotform API key to `wp-config.php`, above the "That's all, stop
   editing!" comment:

`define( 'JOTFORM_API_KEY', '...' );`

3. Go to **Jotform Bridge → Settings**, pick the API region and save. Use
   **Test Connection** to confirm, then **Refresh Forms**.
4. Go to **Jotform Bridge → Integrations**, add an integration, and pick its
   Jotform form and rendering mode.

The constant is the only place the plugin reads the key from: there is no key
field in the admin area and no key in the database. Until the constant is
defined, the plugin's screens show what to add and where, and the value itself
is never displayed beyond its last four characters.

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

No. Deleting the plugin removes only the derived data — synced schemas, the
account form list and the template registry. If you want a full removal,
tick **Delete the integrations and the settings when the plugin is deleted** on
the settings screen first. The API key is not stored by the plugin at all, so
removing it means editing `wp-config.php`.

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
