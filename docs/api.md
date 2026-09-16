# REST API and JavaScript

[Documentation](README.md)

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
always cancel. See [Success redirect](integrations.md#success-redirect).

## Hooks

**[hooks.md](hooks.md) is the reference** — with
signatures and what returning what does. It is generated from the docblocks
beside the calls themselves and checked in CI, so it cannot drift the way the
partial table that used to sit here had already drifted.

The ones most themes reach for first:

| Hook | Type | When |
| --- | --- | --- |
| `jotform_bridge_template_paths` | filter | Directories scanned for templates |
| `jotform_bridge_normalized_schema` | filter | A schema just before it is stored |
| `jotform_bridge_submission_fields` | filter | Sanitized values before mapping |
| `jotform_bridge_spam_check` | filter | Immediately before the upstream call |
| `jotform_bridge_auto_field_html` | filter | Markup of one automatically rendered field |
| `jotform_bridge_before_submit` | action | A validated submission is about to be sent |
| `jotform_bridge_after_submit` | action | Jotform accepted a submission |

`jotform_bridge_auto_field_html` is the one hook whose return value is printed
as raw markup, so a note about it: the `$html` it receives is finished, escaped
output, but the `$field` array beside it is not. Its `label` and
`options[*].label` are text exactly as Jotform reports it, and Jotform allows a
quote or an angle bracket in a field name. Escape anything you take out of
`$field`:

```php
add_filter(
    'jotform_bridge_auto_field_html',
    static function (string $html, array $field): string {
        return '<div class="col">' . $html
            . '<p>' . esc_html($field['label']) . '</p></div>';
    },
    10,
    2
);
```
