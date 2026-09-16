# Getting started

[Documentation](README.md)

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

## Installation

1. **Plugins → Add New → Upload Plugin**, upload the ZIP, activate. Nothing else
   is needed: no `composer install`, no `npm install`, no `npm run build`.
2. Add the API key to `wp-config.php` (see below). Until it is there, the admin
   screens say so and nothing can talk to Jotform.
3. **Jotform Bridge → Settings** — choose the API region, save, then press
   **Check Connection** to confirm the key works.
4. **Jotform Bridge → Integrations → Add Integration** — name it, paste the
   Jotform form ID, press **Connect form**, and choose a rendering mode.
5. Save the integration and render it with the shortcode or PHP helper.
   **Connect form** loads a schema when none exists; use **Sync Schema** to
   refresh an existing definition.

The form ID is the digits at the end of the form URL — the `240000000000001` in
`form.jotform.com/240000000000001`. The plugin never asks Jotform what forms your
account holds; it fetches only the forms you name.

Get an API key from your Jotform account under **Settings → API**. A read-only
key is enough to connect forms and load schemas, but creating submissions needs a
key with write access.

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
