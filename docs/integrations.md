# Integrations

[Documentation](README.md)

## Integrations

An **integration** is the local entity your theme addresses. It binds:

```text
slug  →  Jotform form  →  rendering mode  →  optional template
```

| Field | Meaning |
| --- | --- |
| Name | What you see in the admin |
| Slug | The public identifier used in code, e.g. `contact` |
| Jotform Form ID | Which form submissions go to, entered by ID and resolved by **Connect form** |
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

## Rendering modes

**Custom template** — a plain PHP file in your theme. Full control over the
markup. This is the main use case.

**Auto** — markup built from the Jotform form definition: semantic elements,
`jfb-` prefixed classes, labels and options from Jotform, no CSS shipped and no
layout opinions. Use it to get an integration live before a template exists, or
for forms whose presentation does not matter. Fields the plugin cannot map are
left out rather than half-rendered, and the admin says which ones.

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
shows its success message instead. The integration editor says so next to the
**Redirect Page** field, and the reason is written to the debug log when logging
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
`window.location.assign()`, after the configured delay, keeping the form busy
the whole time so a second submission is impossible. A theme that wants
its own flow cancels the navigation:

```js
document.addEventListener('jotformbridge:success', function (event) {
    if (event.detail.redirect) {
        event.preventDefault();               // no navigation happens
        showThanksModal(event.detail.redirect.url);
    }
});
```

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

## Connect form, Sync Schema, Check Connection

Administrative reads from Jotform happen only through explicit actions. There is no cron job, no
background refresh and no expiry anywhere in the plugin.

| Action | Where | What it does |
| --- | --- | --- |
| **Connect form** | Integration editor | Reads **one** form by ID (`GET /form/{id}`), stores its title and status, and loads a schema if none exists |
| **Sync Schema** | Integration editor | Reloads **one** form's definition, re-normalizes it, stores it and re-checks compatibility |
| **Check Connection** | Settings | Asks `GET /user` who the key belongs to. Nothing else |
| **Send Test Submission** | Integration editor | Sends one real submission built from the stored schema and shows Jotform's answer verbatim |

There is no action that lists the forms on your account, because the plugin never
asks for one. It stores a record only for the forms your integrations name.

**Check Connection** looks redundant next to **Connect form**, and is not.
Jotform answers a form ID that does not exist, a form belonging to another
account and a wrong API key with an identical `401 You're not authorized to use
(/form-id)`. A failing **Connect form** therefore cannot tell you which of the
three you are looking at. `GET /user` does not mention a form, so if it succeeds
the key is fine and the ID is the problem — and that is the only place in the
plugin where those two are separable.

Templates are not on that list. They are read from the theme whenever the plugin
needs to know what exists — drop a file into `jotform-bridge-templates/`, and it is in the select.
Edit one, and the compatibility check describes the version on disk. There is
nothing to press.

Schemas are stored per Jotform form ID. Syncing updates the definition used by
all integrations bound to that form.

After changing a form in Jotform — adding a field, making one required,
renaming an option — press **Sync Schema** on the integrations that use it. It
reports whether the form actually changed since the last sync, and re-runs the
compatibility check against your template.

An integration without a stored schema cannot run until **Connect form** loads
it or **Sync Schema** succeeds: it does not
render, and submissions to it are refused. The editor says so, and the
integrations list has a **Schema** column showing when each form was last
synced.
