# Fields and validation

[Documentation](README.md)

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

## Known limitations

* **Field coverage.** Only the field types listed in [Fields and validation](fields.md#supported-field-types). File upload, signature,
  payment and date/time fields are not mapped.
* **No Jotform rule import or calculations.** Jotform conditions are not
  evaluated automatically. Configure [local conditional logic](conditional-logic.md)
  on the integration for visibility and conditional requirements.
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
* **JavaScript is required to submit.** The inputs carry semantic identifiers
  rather than `name` attributes, so the form is sent by the plugin's script and
  by nothing else. A visitor without JavaScript is told so — the auto renderer
  prints the notice, and a custom template gets the same line as `$noscript`.
* **A redirect target is a page of this site.** It is picked from the published
  pages, not typed as a URL, and an off-site target is refused by design. To send
  a visitor elsewhere, cancel the redirect on `jotformbridge:success` and
  navigate yourself.

## Email domain validation


Settings → Validation provides an optional email-domain allowlist for every
email field. The restriction is off by default; normal email-format validation
still applies. The initial list includes Gmail, Outlook/Hotmail/Live/MSN,
iCloud and Proton domains. These identify established providers, not verified
mailboxes or a guarantee against spam.

Enter one domain per line, without `@`, URLs or wildcards. Saving lowercases
and deduplicates valid domains and removes invalid lines. Matching is exact
and case-insensitive; subdomains need their own entries. Add business domains
before enabling the restriction if your visitors use company email. An enabled
empty list rejects every nonempty email address. Rejected domains return the
usual HTTP 422 field error, displayed through the template's error slots.
Saving either settings tab preserves the other tab's values.
